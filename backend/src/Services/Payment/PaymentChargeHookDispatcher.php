<?php

/**
 * @copyright © Melograno Venture Studio. All rights reserved.
 * @licence   See COPYING.md for license details.
 */

declare(strict_types=1);

namespace IvyForms\Services\Payment;

use WP_REST_Response;

/**
 * Fires add-on hooks around a submission charge without owning payment persistence.
 */
class PaymentChargeHookDispatcher
{
    /**
     * A charge finished, but the entry or payment row was not saved.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $paymentOutcome
     */
    public function dispatchAbandonedCharge(array $context, array $paymentOutcome): void
    {
        if (($paymentOutcome['paymentResult'] ?? null) === null) {
            return;
        }

        $this->dispatchFailureIfNeeded(
            $context,
            array_merge($paymentOutcome, [
                'errorResponse' => new WP_REST_Response(['payment_error' => true]),
            ])
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $paymentOutcome
     */
    public function dispatchFailureIfNeeded(array $context, array $paymentOutcome): void
    {
        if (!(($paymentOutcome['errorResponse'] ?? null) instanceof WP_REST_Response)) {
            return;
        }

        $chargeContext = $this->chargeContext($context, $paymentOutcome);

        /**
         * Allow to hook into a failed payment charge so add-ons can release reservations.
         *
         * @since 1.6.0
         *
         * Arguments:
         * - array $context Payment context (formId, formFields, submissionData, couponId, …)
         *
         * Example usage:
         * add_action('ivyforms/payment/after_failed_charge', function ($context) {
         *     // …
         * }, 10, 1);
         */
        try {
            do_action('ivyforms/payment/after_failed_charge', $chargeContext);
        } catch (\Throwable $exception) {
            error_log('[IvyForms] after_failed_charge hook failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $paymentOutcome
     */
    public function dispatchSuccessIfNeeded(int $entryId, array $context, array $paymentOutcome): void
    {
        if ($paymentOutcome['paymentResult'] === null || !empty($paymentOutcome['requiresAction'])) {
            return;
        }

        $chargeContext = $this->chargeContext($context, $paymentOutcome);
        $chargeContext['paymentTotal'] = $paymentOutcome['paymentTotal'];
        $chargeContext['paymentCurrency'] = $paymentOutcome['paymentCurrency'];
        $chargeContext['paymentResult'] = $paymentOutcome['paymentResult'];

        /**
         * Allow to hook into a completed payment charge, including a $0 skip.
         *
         * @since 1.6.0
         *
         * Arguments:
         * - int $entryId Entry ID (0 when entries are not stored)
         * - array $context Payment context (formId, formFields, submissionData, paymentTotal, …)
         *
         * Example usage:
         * add_action('ivyforms/payment/after_successful_charge', function ($entryId, $context) {
         *     // …
         * }, 10, 2);
         */
        try {
            do_action('ivyforms/payment/after_successful_charge', $entryId, $chargeContext);
        } catch (\Throwable $exception) {
            error_log('[IvyForms] after_successful_charge hook failed: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $paymentOutcome
     * @return array<string, mixed>
     */
    private function chargeContext(array $context, array $paymentOutcome): array
    {
        $chargeContext = is_array($paymentOutcome['chargeContext'] ?? null)
            ? $paymentOutcome['chargeContext']
            : [];
        $chargeContext['formId'] = $context['formId'];

        if (!isset($chargeContext['formFields'])) {
            $chargeContext['formFields'] = array_map(
                static function ($field) {
                    return method_exists($field, 'toArray') ? $field->toArray() : $field;
                },
                $context['formFields']
            );
        }

        if (!isset($chargeContext['submissionData'])) {
            $chargeContext['submissionData'] = $context['submissionData'];
        }

        return $chargeContext;
    }
}
