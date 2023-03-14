<?php

namespace App\Commands;

class OpenCommand extends Command
{
    protected $signature = 'open {link-key?}';

    protected $description = 'Open the project URL';

    public function handle(): void
    {
        $this->config->validate([
            'links' => ['required', 'array'],
            'links.*' => ['required', 'url']
        ]);

        $links = $this->config->get('links', []);

        if (!empty($links[$this->argument('link-key')])) {
            $url = $links[$this->argument('link-key')];
        } elseif (count($links) == 1) {
            $url = reset($links);
        } else {
            $url = $links[$this->choice(
                'Which link would you like to open?',
                $links,
                array_key_first($links)
            )];
        }

        if ($this->isWSL()) {
            $this->localCmd(['/mnt/c/Windows/explorer.exe', $url])->run();
        } else {
            $this->localCmd(['open', $url])->run();
        }
    }
}
