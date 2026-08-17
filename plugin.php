<?php

declare(strict_types=1);

use Porta\Plugin\Api\AbstractAnalyzerPlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\Support\AsyncProcess;

/**
 * Mago integration, both of its faces: "Format Current File" (Ctrl+Alt+G)
 * rewrites in place AND applies the result into the buffer (one Ctrl+Z away) — and
 * "Lint Current File" (Ctrl+Alt+E) publishes issues as editor diagnostics
 * (inline messages, stripe, Problems panel). Persisted "Format on save" and
 * "Lint on save" settings (toggled from the palette or the plugin's settings
 * page) run either or both on save — both chains format first, then lint the
 * formatted file. Binary from vendor/bin, then the PATH. Everything runs
 * asynchronously on the shared event loop.
 *
 * The lint half IS {@see AbstractAnalyzerPlugin}'s run, so this extends it and
 * supplies the three per-tool facts below. It registers its own commands rather
 * than calling `registerAnalysis()`: mago has two verbs and two toggles, and its
 * ids and labels are user-visible palette entries. The format→lint chain holds
 * ONE single-flight slot across both processes, which is what `analyseWith()`
 * exists for.
 */
return new class('mago', 'mago') extends AbstractAnalyzerPlugin {
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
                    $this->analyse($api, $file);
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

                return;
            }
            if ($lint) {
                $this->analyse($api, $path);
            }
        });
    }

    protected function command(string $binary, string $file): array
    {
        return [$binary, 'lint', '--reporting-format=json', $file];
    }

    /**
     * Mago exits 0 with an unparseable report on some failures, so the exit code
     * alone can never mean "success" here — the JSON parse is the only gate, and
     * a report that decodes is trusted whatever the code was.
     */
    protected function acceptsExitCode(int $code): bool
    {
        return true;
    }

    protected function diagnostics(array $report): array
    {
        $diagnostics = [];
        foreach (self::rowsAt($report, 'issues') as $issue) {
            $diagnostics[] = [
                'line' => $this->primaryLine($issue),
                'message' => trim((string) ($issue['code'] ?? 'mago').': '.(string) ($issue['message'] ?? '')),
                'severity' => match ($issue['level'] ?? '') {
                    'Error' => 1,
                    'Warning' => 2,
                    'Note' => 3,
                    default => 4,
                },
            ];
        }

        return $diagnostics;
    }

    /** Format in place, then optionally lint the formatted file under the same slot. */
    // @mago-expect lint:no-boolean-flag-parameter -- not a mode switch: the two on-save settings are independent, and $andLint is what lets one acquired slot carry the format→lint chain
    private function format(PluginApi $api, string $file, bool $andLint): void
    {
        $binary = $this->acquire($api);
        if (null === $binary) {
            return;
        }

        $before = $this->textBefore($api, $file);
        new AsyncProcess([$binary, 'format', $file], $api->projectRoot(), timeout: AsyncProcess::INTERACTIVE_TOOL_TIMEOUT)->start(function (int $code) use (
            $api,
            $file,
            $andLint,
            $binary,
            $before,
        ): void {
            if (0 !== $code) {
                $this->release();
                $this->flashFailure($api, $code);

                return;
            }
            $this->applyFormatted($api, $file, $before);
            $api->flash('mago: formatted '.basename($file), 4.0);
            if ($andLint) {
                $this->analyseWith($api, $binary, $file); // keeps the slot for the chain

                return;
            }
            $this->release();
        });
    }

    /** The 0-based line of the issue's Primary annotation (mago spans are already 0-based). */
    private function primaryLine(array $issue): int
    {
        foreach (self::rowsAt($issue, 'annotations') as $annotation) {
            if ('Primary' !== ($annotation['kind'] ?? '')) {
                continue;
            }
            $start = self::arrayAt(self::arrayAt($annotation, 'span'), 'start');

            return max(0, (int) ($start['line'] ?? 0));
        }

        return 0;
    }
};
