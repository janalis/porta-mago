<?php

declare(strict_types=1);

use Porta\Plugin\PluginApi;
use Porta\Plugin\PortaPlugin;
use Porta\Support\AsyncProcess;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Mago integration, both of its faces: "Format Current File" (Ctrl+Alt+G)
 * rewrites in place — the filesystem poller reloads the buffer — and
 * "Lint Current File" (Ctrl+Alt+E) publishes issues as editor diagnostics
 * (inline messages, stripe, Problems panel). Persisted "Format on save" and
 * "Lint on save" settings (toggled from the palette or the plugin's settings
 * page) run either or both on save — both chains format first, then lints the
 * formatted file. Binary from vendor/bin, then the PATH. Everything runs
 * asynchronously on the shared event loop.
 */
return new class implements PortaPlugin {
    private bool $running = false;

    public function name(): string
    {
        return 'mago';
    }

    public function activate(PluginApi $api): void
    {
        $api->registerSetting('binary', 'mago binary', 'text', '', hint: 'empty = vendor/bin, then PATH');
        $api->registerSetting('autoFormat', 'Format on save', 'bool', false);
        $api->registerSetting('autoLint', 'Lint on save', 'bool', false);
        $api->registerInstaller('mago', fn (): bool => null !== $this->binary($api), [['composer', 'carthage-software/mago']]);

        $api->registerCommand(
            'format',
            'Mago: Format Current File',
            function () use ($api): void {
                $file = $this->phpFileOrFlash($api);
                if (null !== $file) {
                    $this->format($api, $file, andLint: false);
                }
            },
            ['ctrl+alt+g'],
        );

        $api->registerCommand(
            'lint',
            'Mago: Lint Current File',
            function () use ($api): void {
                $file = $this->phpFileOrFlash($api);
                if (null !== $file) {
                    $this->lint($api, $file);
                }
            },
            ['ctrl+alt+e'],
        );

        $api->registerCommand('toggle-auto-format', 'Mago: Toggle Format on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoFormat');
            $api->changeSetting('autoFormat', $next);
            $api->flash('mago format on save: '.($next ? 'on' : 'off'));
        });

        $api->registerCommand('toggle-auto-lint', 'Mago: Toggle Lint on Save', static function () use ($api): void {
            $next = !(bool) $api->setting('autoLint');
            $api->changeSetting('autoLint', $next);
            $api->flash('mago lint on save: '.($next ? 'on' : 'off'));
        });

        $usable = fn (): bool => null !== $this->binary($api);
        $api->addMenuItem('format', $usable);
        $api->addMenuItem('lint', $usable);
        $api->addMenuItem('toggle-auto-format');
        $api->addMenuItem('toggle-auto-lint');

        $api->onFileSaved(function (string $path) use ($api): void {
            if (!str_ends_with($path, '.php')) {
                return;
            }
            $format = (bool) $api->setting('autoFormat');
            $lint = (bool) $api->setting('autoLint');
            if ($format) {
                $this->format($api, $path, andLint: $lint);
            } elseif ($lint) {
                $this->lint($api, $path);
            }
        });
    }

    private function phpFileOrFlash(PluginApi $api): ?string
    {
        $file = $api->activeFile();
        if (null === $file || !str_ends_with($file, '.php')) {
            $api->flash('mago: open a PHP file first');

            return null;
        }

        return $file;
    }

    private function format(PluginApi $api, string $file, bool $andLint): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }

        new AsyncProcess([$binary, 'format', $file], $api->projectRoot())->start(function (int $code) use ($api, $file, $andLint, $binary): void {
            if (0 !== $code) {
                $this->running = false;
                $api->flash('mago: exited with code '.$code, 4.0);

                return;
            }
            $api->flash('mago: formatted '.basename($file), 4.0);
            if ($andLint) {
                $this->runLint($api, $binary, $file); // keeps the running flag for the chain

                return;
            }
            $this->running = false;
        });
    }

    private function lint(PluginApi $api, string $file): void
    {
        $binary = $this->acquire($api);
        if (null !== $binary) {
            $this->runLint($api, $binary, $file);
        }
    }

    private function runLint(PluginApi $api, string $binary, string $file): void
    {
        $command = [$binary, 'lint', '--reporting-format=json', $file];
        new AsyncProcess($command, $api->projectRoot())->start(function (int $code, string $stdout) use ($api, $file): void {
            $this->running = false;
            $report = json_decode($stdout, true);
            if (!is_array($report)) {
                $api->flash('mago: exited with code '.$code, 4.0);

                return;
            }

            $diagnostics = [];
            foreach ($report['issues'] ?? [] as $issue) {
                $diagnostics[] = [
                    'line' => $this->primaryLine($issue),
                    'message' => trim(($issue['code'] ?? 'mago').': '.($issue['message'] ?? '')),
                    'severity' => match ($issue['level'] ?? '') {
                        'Error' => 1,
                        'Warning' => 2,
                        'Note' => 3,
                        default => 4,
                    },
                ];
            }

            $api->setDiagnostics($file, $diagnostics);
            $count = count($diagnostics);
            $api->flash(match (true) {
                0 === $count => 'mago: no issues in '.basename($file),
                1 === $count => 'mago: 1 issue in '.basename($file),
                default => "mago: {$count} issues in ".basename($file),
            }, 4.0);
        });
    }

    /** The 0-based line of the issue's Primary annotation (mago spans are already 0-based). */
    private function primaryLine(array $issue): int
    {
        foreach ($issue['annotations'] ?? [] as $annotation) {
            if ('Primary' === ($annotation['kind'] ?? '')) {
                return max(0, (int) ($annotation['span']['start']['line'] ?? 0));
            }
        }

        return 0;
    }

    /** Resolve the binary and take the single-flight slot, or flash and return null. */
    private function acquire(PluginApi $api): ?string
    {
        if ($this->running) {
            $api->flash('mago: already running');

            return null;
        }
        $binary = $this->binary($api);
        if (null === $binary) {
            $api->flash('mago not found (vendor/bin or PATH)', 4.0);

            return null;
        }
        $this->running = true;

        return $binary;
    }

    private function binary(PluginApi $api): ?string
    {
        $configured = trim((string) $api->setting('binary'));
        if ('' !== $configured) {
            return $configured;
        }
        $local = $api->projectRoot().'/vendor/bin/mago';
        if (is_file($local) && is_executable($local)) {
            return $local;
        }

        return new ExecutableFinder()->find('mago');
    }
};
