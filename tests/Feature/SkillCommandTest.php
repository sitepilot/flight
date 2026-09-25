<?php

const LINK_QUESTION = 'Link it into .claude/skills for Claude Code, which only reads skills from there?';

beforeEach(function () {
    $this->root = flightProject();
});

it('installs the skill in .agents/skills', function () {
    $this->artisan('skill')
        ->expectsConfirmation(LINK_QUESTION, 'no')
        ->expectsOutputToContain('Installed')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/.agents/skills/flight/SKILL.md'))
        ->toBe(file_get_contents(base_path('resources/skills/flight/SKILL.md')));
});

it('updates a skill that is already installed', function () {
    mkdir($this->root.'/.agents/skills/flight', 0755, true);
    file_put_contents($this->root.'/.agents/skills/flight/SKILL.md', 'old');

    $this->artisan('skill')
        ->expectsConfirmation(LINK_QUESTION, 'no')
        ->expectsOutputToContain('Updated')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/.agents/skills/flight/SKILL.md'))->not->toBe('old');
});

it('links the skill for Claude Code when asked', function () {
    $this->artisan('skill')
        ->expectsConfirmation(LINK_QUESTION, 'yes')
        ->assertExitCode(0);

    $link = $this->root.'/.claude/skills/flight';

    // Relative, so it keeps working when the repository is cloned elsewhere.
    expect(readlink($link))->toBe('../../.agents/skills/flight')
        ->and($link.'/SKILL.md')->toBeFile();
});

it('leaves out the link when declined', function () {
    $this->artisan('skill')
        ->expectsConfirmation(LINK_QUESTION, 'no')
        ->assertExitCode(0);

    expect(file_exists($this->root.'/.claude/skills/flight'))->toBeFalse();
});

it('does not ask again once linked', function () {
    mkdir($this->root.'/.claude/skills', 0755, true);
    symlink('../../.agents/skills/flight', $this->root.'/.claude/skills/flight');

    $this->artisan('skill')
        ->expectsOutputToContain('Claude Code already links to it')
        ->assertExitCode(0);
});

it('keeps a Claude Code skill of the same name', function () {
    mkdir($this->root.'/.claude/skills/flight', 0755, true);
    file_put_contents($this->root.'/.claude/skills/flight/SKILL.md', 'own');

    $this->artisan('skill')
        ->expectsOutputToContain('Not linked for Claude Code')
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/.claude/skills/flight/SKILL.md'))->toBe('own');
});

it('installs in the home directory with --global', function () {
    $home = $_SERVER['HOME'];
    $_SERVER['HOME'] = $this->projectDirectory;

    try {
        $this->artisan('skill --global')
            ->expectsConfirmation(LINK_QUESTION, 'yes')
            ->assertExitCode(0);
    } finally {
        $_SERVER['HOME'] = $home;
    }

    expect($this->projectDirectory.'/.agents/skills/flight/SKILL.md')->toBeFile()
        ->and($this->projectDirectory.'/.claude/skills/flight/SKILL.md')->toBeFile()
        ->and($this->root.'/.agents')->not->toBeDirectory();
});
