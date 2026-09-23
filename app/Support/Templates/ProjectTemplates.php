<?php

namespace App\Support\Templates;

/**
 * The built-in templates, read from config/templates.php.
 *
 * Validated on use rather than on boot: a broken template should fail the request
 * that reaches for it, loudly and with the key in the message, not take the whole
 * application down at start-up over a template nobody has picked.
 */
class ProjectTemplates
{
    /** @var array<string, ProjectTemplate> */
    private array $parsed = [];

    private bool $parsedEverything = false;

    /**
     * @return array<string, ProjectTemplate>
     *
     * @throws InvalidTemplate
     */
    public function all(): array
    {
        if ($this->parsedEverything) {
            return $this->parsed;
        }

        $raw = (array) config('templates.templates', []);

        if ($raw === []) {
            throw new InvalidTemplate('config/templates.php defines no templates.');
        }

        foreach ($raw as $key => $definition) {
            $this->parsed[(string) $key] = ProjectTemplate::fromConfig((string) $key, $definition);
        }

        $this->parsedEverything = true;

        return $this->parsed;
    }

    /**
     * One template, parsed on its own.
     *
     * Deliberately not `$this->all()[$key]`: a template nobody picked being broken
     * should not refuse a project created from a different one. The blast radius of
     * a bad edit to config/templates.php stays with the template that was edited.
     *
     * @throws InvalidTemplate
     */
    public function find(string $key): ProjectTemplate
    {
        if (isset($this->parsed[$key])) {
            return $this->parsed[$key];
        }

        $raw = ((array) config('templates.templates', []))[$key]
            ?? throw new InvalidTemplate("There is no project template named [{$key}].");

        return $this->parsed[$key] = ProjectTemplate::fromConfig($key, $raw);
    }

    /**
     * What a project gets when nobody chose anything.
     *
     * @throws InvalidTemplate
     */
    public function fallback(): ProjectTemplate
    {
        $key = (string) config('templates.default');

        // Falling back to "the first one" would make the default depend on array
        // order, and a typo here would quietly change what every new project gets.
        if (! array_key_exists($key, (array) config('templates.templates', []))) {
            throw new InvalidTemplate(
                "config/templates.php names [{$key}] as the default, and there is no such template."
            );
        }

        return $this->find($key);
    }

    /** @throws InvalidTemplate */
    public function findOrFallback(?string $key): ProjectTemplate
    {
        return $key === null || $key === '' ? $this->fallback() : $this->find($key);
    }

    /**
     * The keys that exist, without checking whether they are usable.
     *
     * Deliberately not `array_keys($this->all())`: this answers "is that a real
     * choice?" for the create form, and one malformed template should refuse the
     * project that picks it rather than every project anybody creates. The one that
     * was picked is validated when it is applied, inside the transaction.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys((array) config('templates.templates', [])));
    }

    /** @return array<int, array<string, mixed>> */
    public function summaries(): array
    {
        return array_values(array_map(fn (ProjectTemplate $t) => $t->summary(), $this->all()));
    }
}
