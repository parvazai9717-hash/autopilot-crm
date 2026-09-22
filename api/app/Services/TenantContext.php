<?php

namespace App\Services;

class TenantContext
{
    protected static ?int $orgId = null;
    protected static bool $bypassed = false;

    /**
     * Set the current tenant organization ID.
     */
    public static function set(?int $orgId): void
    {
        static::$orgId = $orgId;
    }

    /**
     * Get the current tenant organization ID.
     */
    public static function get(): ?int
    {
        return static::$orgId;
    }

    /**
     * Check if a valid tenant context is currently set.
     */
    public static function has(): bool
    {
        return static::$orgId !== null && static::$orgId > 0;
    }

    /**
     * Clear the current tenant context.
     */
    public static function clear(): void
    {
        static::$orgId = null;
        static::$bypassed = false;
    }

    /**
     * Check if tenant scoping is currently explicitly bypassed.
     */
    public static function isBypassed(): bool
    {
        return static::$bypassed;
    }

    /**
     * Execute a callback with tenant scoping explicitly bypassed.
     * Restores previous bypass state after execution.
     */
    public static function bypass(callable $callback): mixed
    {
        $previousBypass = static::$bypassed;
        static::$bypassed = true;

        try {
            return $callback();
        } finally {
            static::$bypassed = $previousBypass;
        }
    }

    /**
     * Execute a callback scoped to a specific tenant ID.
     * Restores previous tenant context after execution.
     */
    public static function withTenant(int $orgId, callable $callback): mixed
    {
        $previousOrgId = static::$orgId;
        static::$orgId = $orgId;

        try {
            return $callback();
        } finally {
            static::$orgId = $previousOrgId;
        }
    }
}
