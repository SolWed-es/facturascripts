<?php
/**
 * Stripe Utility Functions
 *
 * Provides robust utilities for handling Stripe data, particularly
 * date calculations for subscriptions across different API versions.
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Lib;

use DateTime;

class StripeUtils
{
    /**
     * Get the end date from a Stripe subscription object.
     * Handles multiple Stripe API versions with proper fallbacks.
     *
     * @param object $subscription Stripe subscription object
     *
     * @return string Date string in Y-m-d format
     */
    public static function getSubscriptionEndDate(object $subscription): string
    {
        $now = time();
        $currentPeriodEnd = $subscription->current_period_end ?? null;
        $billingCycleAnchor = $subscription->billing_cycle_anchor ?? null;

        // Extract interval data
        $interval = 'month';
        $intervalCount = 1;
        if (isset($subscription->items->data[0]->price->recurring)) {
            $recurring = $subscription->items->data[0]->price->recurring;
            $interval = $recurring->interval ?? 'month';
            $intervalCount = $recurring->interval_count ?? 1;
        }

        // Priority 1: Use current_period_end if available and in the future
        if ($currentPeriodEnd !== null && $currentPeriodEnd > $now) {
            return date('Y-m-d', $currentPeriodEnd);
        }

        // Priority 2: Calculate from billing_cycle_anchor
        if ($billingCycleAnchor !== null) {
            $date = new DateTime();
            $date->setTimestamp($billingCycleAnchor);
            $date->modify("+{$intervalCount} {$interval}s");
            $endTimestamp = $date->getTimestamp();

            // Ensure end date is in the future by advancing periods
            while ($endTimestamp <= $now) {
                $date->modify("+{$intervalCount} {$interval}s");
                $endTimestamp = $date->getTimestamp();
            }

            return date('Y-m-d', $endTimestamp);
        }

        // Priority 3: Default to interval from now (last resort)
        $date = new DateTime();
        $date->modify("+{$intervalCount} {$interval}s");
        return $date->format('Y-m-d');
    }

    /**
     * Map Stripe subscription status to Suscripcion estado.
     *
     * @param string $stripeStatus Stripe subscription status
     *
     * @return string Suscripcion estado constant
     */
    public static function mapStripeStatusToSuscripcion(string $stripeStatus): string
    {
        $statusMap = [
            'active' => 'activo',
            'past_due' => 'suspendido',
            'canceled' => 'cancelado',
            'unpaid' => 'suspendido',
            'incomplete' => 'pendiente',
            'incomplete_expired' => 'cancelado',
            'trialing' => 'activo',
            'paused' => 'suspendido',
        ];

        return $statusMap[$stripeStatus] ?? 'activo';
    }

    /**
     * Format amount from Stripe cents to decimal.
     *
     * @param int    $amountInCents Amount in cents
     * @param string $currency      Currency code (for potential decimal adjustment)
     *
     * @return float Amount in decimal
     */
    public static function formatAmount(int $amountInCents, string $currency = 'eur'): float
    {
        // Most currencies use 2 decimal places, but some (JPY, etc.) use 0
        $zeroDecimalCurrencies = ['jpy', 'krw', 'vnd', 'bif', 'clp', 'djf', 'gnf', 'kmf', 'mga', 'pyg', 'rwf', 'ugx', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];

        if (in_array(strtolower($currency), $zeroDecimalCurrencies)) {
            return (float) $amountInCents;
        }

        return $amountInCents / 100;
    }
}
