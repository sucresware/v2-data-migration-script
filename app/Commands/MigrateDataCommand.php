<?php

namespace App\Commands;

use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;
use Spatie\Regex\Regex;

class MigrateDataCommand extends Command
{
    protected $signature = 'migrate:data';

    public function handle()
    {
        $this->task('Verifying configuration', function () {
            $this
                ->defineDbConnections()
                ->checkDbConnections()
                ->checkStoragePaths();
        });

        $this->task('Copying data', function () {
            $this->line('');

            collect([
                'achievements',
                'activity_log',
                'bans',
                'failed_jobs',
                'firewall',
                'has_read_discussions_users',
                'notifications',
                'post_reaction_user',
                'reactions',
                'roles',
                'subscribed_discussions_users',
                'user_achievement',
                'users',
                'users_discussions',
            ])->each(fn ($table) => $this->copy($table));

            collect([
                'categories' => 'boards',
                'discussions' => 'threads',
                'posts' => 'replies',
            ])->each(fn ($new_table, $table) => $this->copy($table, $new_table));
        });

        $this->task('Converting categories to boards', function () {
            $db = DB::connection('next');
            $boards = (clone $db)->table('boards');

            // Map old categories with new boards
            (clone $boards)->where('slug', 'annonces')
                ->update(['slug' => 'mod', 'name' => 'Annonces', 'description' => 'Annonces de la plateforme et de la modération']);
            (clone $boards)->where('slug', 'general')
                ->update(['slug' => 'random', 'name' => 'Random', 'description' => 'Tout ce qui ne correspond à aucune autre board']);
            (clone $boards)->where('slug', 'jeux')
                ->update(['slug' => 'games', 'name' => 'Jeux', 'description' => 'thread sur les jeux vidéos']);
            (clone $boards)->where('slug', 'nsfw')
                ->update(['slug' => 'nsfw', 'name' => 'NSFW']);
            (clone $boards)->where('slug', 'tech')
                ->update(['slug' => 'dev', 'name' => 'Développement']);
            (clone $boards)->where('slug', 'anime')
                ->update(['slug' => 'anime', 'name' => 'Anime & Manga']);
            (clone $boards)->where('slug', 'pol')
                ->update(['slug' => 'pol', 'name' => 'Politiquement incorrect']);

            // Merge the rest in /b/random
            $all_in_random = [
                (clone $boards)->where('slug', 'lifehacks')->first()->id,
                (clone $boards)->where('slug', 'shitpost')->first()->id,
                (clone $boards)->where('slug', 'partage-vidéo')->first()->id,
                (clone $boards)->where('slug', 'olinux')->first()->id,
            ];

            $random_board_id = (clone $boards)->where('slug', 'random')->first()->id;
            foreach ($all_in_random as $board_id) {
                (clone $db)->table('threads')->where('board_id', $board_id)->update(['board_id' => $random_board_id]);
                (clone $boards)->where('id', $board_id)->delete();
            }
        });

        $this->task('Stripping unsupported tags from replies', function () {
            DB::connection('next')
                ->table('replies')
                ->where('body', 'LIKE', '%[center]%')
                ->eachById(function ($row) {
                    $row->body = Regex::replace('/\[center\]((?:.|\n)*?)\[\/center\]/m', '$1', $row->body)->result();

                    DB::connection('next')
                        ->table('replies')
                        ->where('id', $row->id)
                        ->update(json_decode(json_encode($row), true));
                }, 500);
        });

        $this->task('Flattening quotes', function () {
            $replacements = [];

            DB::connection('next')
                ->table('replies')
                ->where('body', 'LIKE', '%#p:%')
                ->orderBy('id', 'DESC')
                ->eachById(function ($row) use (&$replacements) {
                    $matchs = Regex::matchAll('/(?:#p:)(?:(\w|-)*)/m', $row->body);

                    foreach ($matchs->results() as $match) {
                        $excerpt = trim($match->group(0));
                        $target_id = trim(str_replace(['#p:'], '', $excerpt));

                        if ($target_id == $row->id) {
                            continue;
                        }

                        $quoted_reply = DB::connection('next')
                            ->table('replies')
                            ->where('replies.id', $target_id)
                            ->join('threads', 'replies.thread_id', 'threads.id')
                            ->join('users', 'replies.user_id', 'users.id')
                            ->first();

                        if (
                            ! $quoted_reply
                            || ($quoted_reply && $quoted_reply->private)
                        ) {
                            continue;
                        }

                        $row->body = str_replace(
                            $excerpt,
                            collect(explode(PHP_EOL, $quoted_reply->body))
                                ->map(fn ($l) => '> ' . $l)
                                ->join(PHP_EOL) . PHP_EOL
                                . '>' . PHP_EOL
                                . '> – <cite>@' . $quoted_reply->name . '</cite>'
                                . PHP_EOL,
                            $row->body
                        );

                        $replacements[$row->id] = $row->body;
                    }
                }, 500);

            collect($replacements)->each(
                fn ($body, $id) => DB::connection('next')
                    ->table('replies')
                    ->where('id', $id)
                    ->update(['body' => $body])
            );
        });
    }

    public function defineDbConnections()
    {
        Config::set('database.connections.legacy', array_merge(
            Config::get('database.connections.' . env('LEGACY_DB_CONNECTION')),
            [
                'host' => env('LEGACY_DB_HOST', '127.0.0.1'),
                'port' => env('LEGACY_DB_PORT', '3306'),
                'database' => env('LEGACY_DB_DATABASE', 'forge'),
                'username' => env('LEGACY_DB_USERNAME', 'forge'),
                'password' => env('LEGACY_DB_PASSWORD', ''),
            ]
        ));

        Config::set('database.connections.next', array_merge(
            Config::get('database.connections.' . env('NEXT_DB_CONNECTION')),
            [
                'host' => env('NEXT_DB_HOST', '127.0.0.1'),
                'port' => env('NEXT_DB_PORT', '3306'),
                'database' => env('NEXT_DB_DATABASE', 'forge'),
                'username' => env('NEXT_DB_USERNAME', 'forge'),
                'password' => env('NEXT_DB_PASSWORD', ''),
            ]
        ));

        return $this;
    }

    public function checkDbConnections()
    {
        DB::connection('legacy')->table('users')->first();
        DB::connection('next')->table('users')->first();

        return $this;
    }

    public function checkStoragePaths()
    {
        throw_if(! file_exists(env('LEGACY_APP_PATH') . '/artisan'), FileNotFoundException::class);
        throw_if(! file_exists(env('NEXT_APP_PATH') . '/artisan'), FileNotFoundException::class);

        return $this;
    }

    public function copy($table, $new_table = null)
    {
        $new_table = $new_table ?? $table;

        $this->line('Copying data from ' . $table . ' to ' . $new_table);

        DB::connection('legacy')
        ->table($table)
        ->chunkById(
            500,
            fn ($chunk) => DB::connection('next')
                ->table($new_table)
                ->insert($this->transformChunk($chunk, $new_table))
        );

        return $this;
    }

    public function transformChunk($chunk, $new_table)
    {
        $chunk->map(fn ($row) => $this->transformRow($row, $new_table));

        return json_decode(json_encode($chunk), true);
    }

    public function transformRow($row, $new_table)
    {
        switch ($new_table) {
            case 'users':
                unset($row->api_token);
                break;
            case 'boards':
                unset($row->order);
                break;
            case 'threads':
                $row->board_id = $row->category_id;
                unset($row->category_id);
                break;
            case 'replies':
                $row->thread_id = $row->discussion_id;
                unset($row->discussion_id);
                break;
        }

        return $row;
    }
}
