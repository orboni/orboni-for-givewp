<?php

use Give\Log\Log;

defined('ABSPATH') || exit;

/**
 * GiveWP's own log (Donations → Tools → Logs), source "Orboni". Requests
 * and webhooks are written while the gateway's debug setting is on; errors
 * always. Never given a key or a signing secret.
 */
class Orboni_GiveWP_Log
{
    /** @param  array<string, mixed>  $context */
    public static function info($message, array $context = [])
    {
        if (Orboni_GiveWP_Settings::debug()) {
            Log::info('Orboni: '.$message, self::context($context));
        }
    }

    /** @param  array<string, mixed>  $context */
    public static function error($message, array $context = [])
    {
        // A charity asking why crypto failed needs these whatever the setting.
        Log::error('Orboni: '.$message, self::context($context));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private static function context(array $context)
    {
        return array_merge(['category' => 'payment', 'source' => 'orboni'], $context);
    }
}
