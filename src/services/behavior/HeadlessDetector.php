<?php

declare(strict_types=1);

namespace larikmc\antibot\services\behavior;

/**
 * Headless Browser & Automated Environment Detector.
 *
 * Catches headless browsers (Puppeteer, Selenium, Playwright, undetected-chromedriver)
 * running on cloud servers by inspecting WebGL GPU renderers (SwiftShader, llvmpipe, Mesa),
 * automation flags (navigator.webdriver), and display geometry anomalies.
 *
 * @author LarikMC <https://64web.ru>
 * @license MIT
 */
class HeadlessDetector
{
    /**
     * Regex pattern matching software rasterizers and virtualized GPU drivers.
     */
    public const SOFTWARE_GPU_REGEX = '/SwiftShader|llvmpipe|Mesa OffScreen|Software Rasterizer|VirtualBox|VMware|Bochs|QEMU/i';

    /**
     * Analyzes client browser environment telemetry gathered during challenge execution.
     *
     * @param array<string, mixed> $telemetry
     * @return array{isHeadless: bool, reason: ?string, details: array<string, mixed>}
     */
    public static function analyze(array $telemetry): array
    {
        $reasons = [];

        // 1. Explicit automation flag
        $webdriver = !empty($telemetry['webdriver']) || ($telemetry['webdriver'] ?? null) === '1' || ($telemetry['webdriver'] ?? null) === true;
        if ($webdriver) {
            $reasons[] = 'navigator.webdriver flag active';
        }

        // 2. WebGL Software / Virtualized GPU Renderer
        $gpu = trim((string) ($telemetry['gpu'] ?? ''));
        if ($gpu !== '' && self::isSoftwareGpu($gpu)) {
            $reasons[] = 'Software/Headless WebGL GPU detected: ' . $gpu;
        }

        // 3. Screen / Window dimension anomaly (0x0 screen or zero viewport)
        $screenWidth = isset($telemetry['screen_w']) ? (int) $telemetry['screen_w'] : -1;
        $screenHeight = isset($telemetry['screen_h']) ? (int) $telemetry['screen_h'] : -1;
        $outerWidth = isset($telemetry['outer_w']) ? (int) $telemetry['outer_w'] : -1;
        $outerHeight = isset($telemetry['outer_h']) ? (int) $telemetry['outer_h'] : -1;

        if ($screenWidth === 0 || $screenHeight === 0 || ($outerWidth === 0 && $outerHeight === 0 && $outerWidth !== -1)) {
            $reasons[] = 'Zero or anomalous screen dimensions (Headless display)';
        }

        // 4. Known automation framework artifacts
        if (!empty($telemetry['artifacts'])) {
            $reasons[] = 'Automation artifact detected: ' . $telemetry['artifacts'];
        }

        $isHeadless = !empty($reasons);

        return [
            'isHeadless' => $isHeadless,
            'reason' => $isHeadless ? implode('; ', $reasons) : null,
            'details' => [
                'webdriver' => $webdriver,
                'gpu' => $gpu,
                'screen' => "{$screenWidth}x{$screenHeight}",
                'outer' => "{$outerWidth}x{$outerHeight}",
            ],
        ];
    }

    /**
     * Returns true if the WebGL renderer string indicates a software or virtualized rasterizer.
     *
     * @param string $renderer
     * @return bool
     */
    public static function isSoftwareGpu(string $renderer): bool
    {
        return (bool) preg_match(self::SOFTWARE_GPU_REGEX, $renderer);
    }

    /**
     * Generates a lightweight (~2ms execution) client-side telemetry probe snippet.
     *
     * @return string
     */
    public static function getClientProbeJs(): string
    {
        return <<<'JS'
(function() {
    var d = {
        webdriver: navigator.webdriver ? 1 : 0,
        gpu: '',
        screen_w: screen.width || 0,
        screen_h: screen.height || 0,
        outer_w: window.outerWidth || 0,
        outer_h: window.outerHeight || 0,
        artifacts: ''
    };
    try {
        var canvas = document.createElement('canvas');
        var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
        if (gl) {
            var dbg = gl.getExtension('WEBGL_debug_renderer_info');
            if (dbg) {
                d.gpu = gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL) || '';
            }
        }
    } catch (e) {}
    try {
        var arts = [];
        if (window._phantom || window.callPhantom) arts.push('PhantomJS');
        if (window.__nightmare) arts.push('Nightmare');
        if (window.__selenium_evaluate || window.__webdriver_evaluate || window.__driver_evaluate) arts.push('Selenium');
        if (arts.length > 0) d.artifacts = arts.join(',');
    } catch (e) {}
    return d;
})()
JS;
    }
}
