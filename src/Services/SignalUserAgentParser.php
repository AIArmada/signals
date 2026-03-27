<?php

declare(strict_types=1);

namespace AIArmada\Signals\Services;

use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;

final class SignalUserAgentParser
{
    /**
     * Parse a User-Agent string into normalized device/browser/OS info.
     *
     * Returns an empty array when UA parsing is disabled in config or the
     * User-Agent string is blank.
     *
     * @return array{
     *     device_type: string|null,
     *     device_brand: string|null,
     *     device_model: string|null,
     *     browser: string|null,
     *     browser_version: string|null,
     *     os: string|null,
     *     os_version: string|null,
     *     is_bot: bool,
     * }
     */
    public function parse(string $userAgent, ?ClientHints $clientHints = null): array
    {
        if (! config('signals.features.ua_parsing.enabled', true)) {
            return $this->emptyResult();
        }

        $userAgent = mb_trim($userAgent);

        if ($userAgent === '') {
            return $this->emptyResult();
        }

        $dd = new DeviceDetector($userAgent, $clientHints);
        $dd->discardBotInformation();
        $dd->parse();

        if ($dd->isBot()) {
            return [
                'device_type' => null,
                'device_brand' => null,
                'device_model' => null,
                'browser' => null,
                'browser_version' => null,
                'os' => null,
                'os_version' => null,
                'is_bot' => true,
            ];
        }

        /** @var array{name?: string, version?: string}|null $clientInfo */
        $clientInfo = $dd->getClient();

        /** @var array{name?: string, version?: string}|null $osInfo */
        $osInfo = $dd->getOs();

        $deviceBrand = $dd->getBrandName();
        $deviceModel = $dd->getModel();
        $deviceType = $this->normalizeDeviceType($dd->getDeviceName());

        return [
            'device_type' => $deviceType !== '' ? $deviceType : null,
            'device_brand' => ($deviceBrand !== '' && $deviceBrand !== 'Unknown') ? $deviceBrand : null,
            'device_model' => ($deviceModel !== '' && $deviceModel !== 'Unknown') ? $deviceModel : null,
            'browser' => $clientInfo['name'] ?? null,
            'browser_version' => isset($clientInfo['version']) && $clientInfo['version'] !== '' ? $clientInfo['version'] : null,
            'os' => $osInfo['name'] ?? null,
            'os_version' => isset($osInfo['version']) && $osInfo['version'] !== '' ? $osInfo['version'] : null,
            'is_bot' => false,
        ];
    }

    /**
     * @return array{device_type: null, device_brand: null, device_model: null, browser: null, browser_version: null, os: null, os_version: null, is_bot: bool}
     */
    private function emptyResult(): array
    {
        return [
            'device_type' => null,
            'device_brand' => null,
            'device_model' => null,
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'is_bot' => false,
        ];
    }

    private function normalizeDeviceType(string $deviceName): string
    {
        return match (mb_strtolower($deviceName)) {
            'smartphone' => 'mobile',
            'feature phone' => 'mobile',
            'tablet' => 'tablet',
            'phablet' => 'tablet',
            'desktop' => 'desktop',
            'tv' => 'tv',
            'smart display' => 'tv',
            'console' => 'console',
            'portable media player' => 'mobile',
            'car browser' => 'desktop',
            'camera' => 'desktop',
            default => $deviceName,
        };
    }
}
