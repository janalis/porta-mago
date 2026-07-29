<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractToolPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\AsyncProcess;

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
return new class('mago', 'mago') extends AbstractToolPlugin {
    public function activate(PluginApi $api): void
    {
        $this->registerBinarySetting($api, [['composer', 'carthage-software/mago']]);
        $api->registerSetting('autoFormat', 'Format on save', 'bool', false);
        $api->registerSetting('autoLint', 'Lint on save', 'bool', false);

        $api->registerCommand(
            'format',
            'Mago: Format Current File',
            function () use ($api): void {
                $file = $this->activeFileOrFlash($api);
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
                $file = $this->activeFileOrFlash($api);
                if (null !== $file) {
                    $this->lint($api, $file);
                }
            },
            ['ctrl+alt+e'],
        );

        $this->registerToggle($api, 'toggle-auto-format', 'Mago: Toggle Format on Save', 'autoFormat', 'mago format on save');
        $this->registerToggle($api, 'toggle-auto-lint', 'Mago: Toggle Lint on Save', 'autoLint', 'mago lint on save');

        $usable = $this->binaryAvailable($api);
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

    private function format(PluginApi $api, string $file, bool $andLint): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }

        new AsyncProcess([$binary, 'format', $file], $api->projectRoot())->start(function (int $code) use ($api, $file, $andLint, $binary): void {
            if (0 !== $code) {
                $this->release();
                $this->flashFailure($api, $code);

                return;
            }
            $api->flash('mago: formatted '.basename($file), 4.0);
            if ($andLint) {
                $this->runLint($api, $binary, $file); // keeps the running flag for the chain

                return;
            }
            $this->release();
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
            $this->release();
            $report = json_decode($stdout, true);
            if (!is_array($report)) {
                // mago exits 0 with an unparseable report on some failures, so
                // the code alone cannot say "success".
                $this->flashFailure($api, $code);

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
            $this->flashCount($api, count($diagnostics), 'issue', $file);
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

};
