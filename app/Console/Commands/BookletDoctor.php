<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BookletDoctor extends Command
{
    protected $signature = 'booklet:doctor';

    protected $description = 'Check that everything Booklet needs from this server is there and working';

    public function handle()
    {
        $checks = \App\Support\Health::run();
        $marks = ['ok' => '<fg=green>✓</>', 'warn' => '<fg=yellow>!</>', 'fail' => '<fg=red>✗</>', 'info' => '<fg=blue>·</>'];
        foreach (\App\Support\Health::GROUPS as $group => $title) {
            $mine = array_filter($checks, fn ($c) => $c['group'] === $group);
            if ($mine === []) {
                continue;
            }
            $this->newLine();
            $this->line('<options=bold>'.$title.'</>');
            foreach ($mine as $c) {
                $this->line(' '.$marks[$c['state']].' '.str_pad($c['label'], 26).$c['text']);
                if ($c['state'] !== 'ok' && $c['fix']) {
                    $this->line('   '.str_repeat(' ', 26).'<fg=gray>'.$c['fix'].'</>');
                }
                if ($c['state'] !== 'ok' && $c['command']) {
                    $this->line('   '.str_repeat(' ', 26).'<fg=cyan>'.$c['command'].'</>');
                }
            }
        }
        $fail = count(array_filter($checks, fn ($c) => $c['state'] === 'fail'));
        $warn = count(array_filter($checks, fn ($c) => $c['state'] === 'warn'));
        $this->newLine();
        if ($fail + $warn === 0) {
            $this->info('All well.');
        } else {
            $this->line(trim(($fail ? $fail.' to fix' : '').($fail && $warn ? ', ' : '').($warn ? $warn.' worth a look' : '')).'.');
        }

        return $fail ? 1 : 0;
    }
}
