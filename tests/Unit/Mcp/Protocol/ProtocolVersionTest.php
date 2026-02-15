<?php

declare(strict_types=1);

use Laragentic\Mcp\Protocol\ProtocolVersion;

describe('ProtocolVersion', function () {
    it('supports the latest version', function () {
        expect(ProtocolVersion::isSupported('2025-11-25'))->toBeTrue();
    });

    it('supports the previous version', function () {
        expect(ProtocolVersion::isSupported('2024-11-05'))->toBeTrue();
    });

    it('rejects unsupported versions', function () {
        expect(ProtocolVersion::isSupported('2023-01-01'))->toBeFalse();
    });

    it('negotiates compatible version', function () {
        expect(ProtocolVersion::negotiate('2025-11-25'))->toBe('2025-11-25');
    });

    it('returns null for incompatible version', function () {
        expect(ProtocolVersion::negotiate('9999-01-01'))->toBeNull();
    });

    it('has the correct latest version constant', function () {
        expect(ProtocolVersion::LATEST)->toBe('2025-11-25');
    });
});
