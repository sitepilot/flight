<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Files;

class SkillCommand extends FlightCommand
{
    /**
     * Relative, so the link keeps working in a clone of the repository.
     */
    protected const string CLAUDE_TARGET = '../../.agents/skills/flight';

    protected $signature = 'skill {--global : Install it for all your projects, in your home directory}';

    protected $description = 'Install the skill that teaches AI agents to use Flight';

    public function handle(): int
    {
        $root = $this->option('global') ? $_SERVER['HOME'] : getcwd();
        $file = $root.'/.agents/skills/flight/SKILL.md';

        $updated = is_file($file);

        Files::put($file, file_get_contents(base_path('resources/skills/flight/SKILL.md')));

        $this->step(($updated ? 'Updated ' : 'Installed ').$this->displayPath($file));

        $this->linkForClaude($root.'/.claude/skills/flight');

        return self::SUCCESS;
    }

    /**
     * Claude Code only reads skills from .claude/skills, while other agents
     * read .agents/skills.
     */
    protected function linkForClaude(string $link): void
    {
        if (is_link($link) && readlink($link) === self::CLAUDE_TARGET) {
            $this->skipped('Claude Code already links to it');

            return;
        }

        if (file_exists($link) || is_link($link)) {
            $this->warning("Not linked for Claude Code: {$this->displayPath($link)} already exists.");

            return;
        }

        if (! $this->confirm('Link it into .claude/skills for Claude Code, which only reads skills from there?', true)) {
            $this->note('Claude Code won\'t see the skill. Run `flight skill` again to link it.');

            return;
        }

        Files::link(self::CLAUDE_TARGET, $link);

        $this->step('Linked '.$this->displayPath($link));
    }
}
