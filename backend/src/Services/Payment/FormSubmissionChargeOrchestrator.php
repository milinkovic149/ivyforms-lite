<?php

namespace IvyForms\Services\Payment;

use IvyForms\Entity\Form\Form;
use IvyForms\Services\Translations\BackendStrings;
use WP_Error;
use WP_REST_Response;

/**
 * Charge dispatch for a public submission (amount, gateway, persist, outcome).
 */
class FormSubmissionChargeOrchestrator
{
    private PaymentAmountCalculator $paymentAmountCalculator;

    private PaymentGatewayRegistry $paymentGatewayRegistry;

    private FormSubmissionPaymentService $paymentService;

    public function __construct(
        PaymentAmountCalculator $paymentAmountCalculator,
        PaymentGatewayRegistry $paymentGatewayRegistry,
        FormSubmissionPaymentService $paymentService
    ) {
        $this->paymentAmountCalculator = $paymentAmountCalculator;
        $this->paymentGatewayRegistry = $paymentGatewayRegistry;
        $this->paymentService = $paymentService;
    }

    /**
     * @param array<string, mixed> $rawParams
     * @param array<int, object> $formFields
     * @param array<string, mixed> $submissionData
     * @return array<string, mixed>
     */
    public function process(
        array $rawParams,
        Form $form,
        array $formFields,
        array $submissionData,
        object $paymentField
    ): array {
        $emptyResult = $this->emptyPaymentOutcome();
        $submissionByTypeIndex = $this->mapSubmissionToTypeIndexKeys($submissionData, $formFields);
        $prepared = $this->prepareChargeableRequest(
            $form,
            $formFields,
            $submissionByTypeIndex,
            $paymentField,
            $rawParams,
            $emptyResult
        );
        if ($prepared['errorResponse'] !== null) {
            return $prepared;
        }

        if (!empty($prepared['skipCharge'])) {
            return $this->skippedChargeOutcome($prepared, $emptyResult);
        }

        return $this->runGatewayCharge(
            $rawParams,
            $form,
            $formFields,
            $submissionData,
            $paymentField,
            $submissionByTypeIndex,
            $prepared,
            $emptyResult
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPaymentOutcome(): array
    {
        return [
            'errorResponse' => null,
            'paymentResult' => null,
            'paymentTotal' => 0.0,
            'paymentCurrency' => 'USD',
            'paymentMethod' => 'square',
            'squareOrderId' => '',
            'squareCustomerId' => null,
            'customerName' => null,
            'requiresAction' => false,
            'clientSecret' => null,
            'paymentIntentId' => null,
            'chargeContext' => [],
        ];
    }

    /**
     * @param array<int, object> $formFields
     * @param array<string, mixed> $submissionByTypeIndex
     * @param array<string, mixed> $rawParams
     * @param array<string, mixed> $emptyResult
     * @return array<string, mixed>
     */
    private function prepareChargeableRequest(
        Form $form,
        array $formFields,
        array $submissionByTypeIndex,
        object $paymentField,
        array $rawParams,
        array $emptyResult
    ): array {
        $currency = strtoupper((string) $form->getPaymentSettings()->getCurrency());
        if ($currency === '') {
            $currency = 'USD';
        }

        $fieldArrays = array_map(static fn($field) => $field->toArray(), $formFields);
        $total = $this->paymentAmountCalculator->calculateTotal(
            $fieldArrays,
            $submissionByTypeIndex,
            $currency
        );
        $chargeContext = [
            'formId' => (int) $form->getId(),
            'formFields' => $fieldArrays,
            'submissionData' => $submissionByTypeIndex,
            'subtotal' => $total,
            'total' => $total,
            'currency' => $currency,
            'rawParams' => $rawParams,
        ];
        if (MoneyMinorUnits::toMinor($total, $currency) < 0) {
            return $this->failedAmountOutcome($emptyResult, $chargeContext);
        }

        $gatewayId = $this->resolveGatewayId($paymentField, $rawParams);
        if ($gatewayId === null) {
            return array_merge($emptyResult, [
                'errorResponse' => $this->paymentErrorResponse(
                    BackendStrings::getPaymentStrings()['payment_gateway_unavailable']
                ),
                'chargeContext' => $chargeContext,
            ]);
        }

        $filtered = $this->filterChargePayload(
            $rawParams,
            $chargeContext,
            $total,
            $currency,
            $gatewayId
        );
        if ($filtered instanceof WP_Error) {
            return array_merge($emptyResult, [
                'errorResponse' => $this->paymentErrorResponse(
                    $filtered->get_error_message() !== ''
                        ? $filtered->get_error_message()
                        : BackendStrings::getPaymentStrings()['payment_failed']
                ),
                'chargeContext' => $chargeContext,
            ]);
        }

        if (!$filtered['skipCharge'] && !MoneyMinorUnits::isChargeable($filtered['total'], $currency)) {
            return $this->failedAmountOutcome($emptyResult, $filtered['chargeContext']);
        }

        return array_merge($emptyResult, [
            'total' => $filtered['total'],
            'gatewayId' => $gatewayId,
            'currency' => $currency,
            'skipCharge' => $filtered['skipCharge'],
            'chargeContext' => $filtered['chargeContext'],
            'errorResponse' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $rawParams
     * @param array<int, object> $formFields
     * @param array<string, mixed> $submissionData
     * @param array<string, mixed> $submissionByTypeIndex
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $emptyResult
     * @return array<string, mixed>
     */
    private function runGatewayCharge(
        array $rawParams,
        Form $form,
        array $formFields,
        array $submissionData,
        object $paymentField,
        array $submissionByTypeIndex,
        array $prepared,
        array $emptyResult
    ): array {
        $gatewayId = (string) $prepared['gatewayId'];
        $chargeContext = is_array($prepared['chargeContext'] ?? null) ? $prepared['chargeContext'] : [];
        try {
            $gateway = $this->paymentGatewayRegistry->get($gatewayId);
        } catch (\Throwable $e) {
            return array_merge($emptyResult, [
                'paymentMethod' => $gatewayId,
                'errorResponse' => $this->paymentErrorResponse(
                    BackendStrings::getPaymentStrings()['payment_gateway_unavailable']
                ),
                'chargeContext' => $chargeContext,
            ]);
        }

        $chargeResult = $gateway->processCharge(
            $rawParams,
            $form,
            $formFields,
            $submissionData,
            $paymentField,
            (float) $prepared['total']
        );
        if ($chargeResult->isFailed()) {
            return $this->failedChargeOutcome($chargeResult, $form, $emptyResult, $chargeContext);
        }

        return $this->successfulChargeOutcome(
            $chargeResult,
            $form,
            $gatewayId,
            $formFields,
            $submissionData,
            $submissionByTypeIndex,
            $emptyResult,
            $chargeContext
        );
    }

    /**
     * @param array<string, mixed> $emptyResult
     * @param array<string, mixed> $chargeContext
     * @return array<string, mixed>
     */
    private function failedChargeOutcome(
        PaymentChargeResult $chargeResult,
        Form $form,
        array $emptyResult,
        array $chargeContext
    ): array {
        $this->paymentService->persistChargeFromResult($form, $chargeResult, $chargeResult->getStatus());

        return array_merge($emptyResult, [
            'paymentMethod' => $chargeResult->getPaymentMethod(),
            'errorResponse' => $this->paymentErrorResponse(
                (string) ($chargeResult->getMessage() ?? BackendStrings::getPaymentStrings()['payment_failed'])
            ),
            'chargeContext' => $chargeContext,
        ]);
    }

    /**
     * @param array<int, object> $formFields
     * @param array<string, mixed> $submissionData
     * @param array<string, mixed> $submissionByTypeIndex
     * @param array<string, mixed> $emptyResult
     * @param array<string, mixed> $chargeContext
     * @return array<string, mixed>
     */
    private function successfulChargeOutcome(
        PaymentChargeResult $chargeResult,
        Form $form,
        string $gatewayId,
        array $formFields,
        array $submissionData,
        array $submissionByTypeIndex,
        array $emptyResult,
        array $chargeContext
    ): array {
        $customerName = SubmissionPaymentCustomerName::resolve(
            $chargeResult->getCustomerName(),
            $form,
            $gatewayId,
            $formFields,
            $submissionData,
            $submissionByTypeIndex
        );
        $this->paymentService->persistChargeFromResult(
            $form,
            $chargeResult,
            FormSubmissionPaymentService::STATUS_PENDING,
            $customerName
        );

        if ($chargeResult->isRequiresAction()) {
            return array_merge($emptyResult, [
                'paymentMethod' => $chargeResult->getPaymentMethod(),
                'paymentTotal' => $chargeResult->getPaymentTotal(),
                'paymentCurrency' => $chargeResult->getPaymentCurrency(),
                'customerName' => $customerName,
                'requiresAction' => true,
                'clientSecret' => $chargeResult->getClientSecret(),
                'paymentIntentId' => $chargeResult->getPaymentIntentId(),
                'chargeContext' => $chargeContext,
                'paymentResult' => [
                    'transactionId' => (string) ($chargeResult->getTransactionId() ?? ''),
                    'paymentMethod' => $chargeResult->getPaymentMethod(),
                    'status' => $chargeResult->getStatus(),
                ],
            ]);
        }

        return [
            'errorResponse' => null,
            'paymentResult' => $chargeResult->toPaymentResultArray(),
            'paymentTotal' => $chargeResult->getPaymentTotal(),
            'paymentCurrency' => $chargeResult->getPaymentCurrency(),
            'paymentMethod' => $chargeResult->getPaymentMethod(),
            'squareOrderId' => (string) ($chargeResult->getSquareOrderId() ?? ''),
            'squareCustomerId' => $chargeResult->getSquareCustomerId(),
            'customerName' => $customerName,
            'requiresAction' => false,
            'clientSecret' => null,
            'paymentIntentId' => null,
            'chargeContext' => $chargeContext,
        ];
    }

    /**
     * @param array<string, mixed> $rawParams
     */
    private function resolveGatewayId(object $paymentField, array $rawParams): ?string
    {
        $fromToken = PaymentMethodFieldConfig::resolveGatewayIdFromToken($rawParams, $paymentField);
        if ($fromToken !== null) {
            return $this->paymentGatewayRegistry->has($fromToken) ? $fromToken : null;
        }

        if (PaymentMethodFieldConfig::resolveGatewayIdFromToken($rawParams) !== null) {
            return null;
        }

        return PaymentMethodFieldConfig::resolvePrimaryGatewayId($paymentField);
    }

    /**
     * @param array<string, mixed> $submissionData
     * @param array<int, object> $formFields
     * @return array<string, mixed>
     */
    private function mapSubmissionToTypeIndexKeys(array $submissionData, array $formFields): array
    {
        $mapped = [];

        foreach ($formFields as $field) {
            if (!method_exists($field, 'getType') || !method_exists($field, 'getIndex')) {
                continue;
            }

            $fieldId = method_exists($field, 'getId') ? $field->getId() : null;
            $type = (string) $field->getType();
            $index = (int) $field->getIndex();
            $key = $type . '_' . $index;

            if ($fieldId !== null && isset($submissionData[$fieldId])) {
                $mapped[$key] = $submissionData[$fieldId];
            } elseif (isset($submissionData[$key])) {
                $mapped[$key] = $submissionData[$key];
            }
        }

        return $mapped;
    }

    /**
     * Let add-ons adjust the charge amount, skip a $0 charge, or reject it.
     *
     * @param array<string, mixed> $rawParams
     * @param array<string, mixed> $chargeContext
     * @return array{skipCharge: bool, total: float, chargeContext: array<string, mixed>}|WP_Error
     */
    private function filterChargePayload(
        array $rawParams,
        array $chargeContext,
        float $total,
        string $currency,
        string $gatewayId
    ) {
        $amountMinor = MoneyMinorUnits::toMinor($total, $currency);
        $chargePayload = [
            'amountCents' => $amountMinor,
            'currency' => $currency,
            'gatewayId' => $gatewayId,
            'skipCharge' => false,
            'sourceId' => (string) ($rawParams['sourceId'] ?? ''),
            'idempotencyKey' => (string) ($rawParams['idempotencyKey'] ?? ''),
        ];

        /**
         * Allow to hook into the charge payload before the gateway call.
         *
         * @since 1.6.0
         *
         * Arguments:
         * - array|WP_Error $payload amountCents, currency, gatewayId, sourceId, idempotencyKey.
         *   A numeric amountCents of 0 skips the gateway. Non-numeric amounts are ignored.
         *   Additive keys are copied onto the charge context.
         * - array $context formId, formFields, submissionData, subtotal, total, currency, rawParams
         *
         * Example usage:
         * add_filter('ivyforms/payment/before_charge', function ($payload, $context) {
         *     return $payload;
         * }, 10, 2);
         */
        $filteredPayload = apply_filters('ivyforms/payment/before_charge', $chargePayload, $chargeContext);
        if ($filteredPayload instanceof WP_Error) {
            return $filteredPayload;
        }

        if (!is_array($filteredPayload)) {
            $filteredPayload = $chargePayload;
        }

        $rawAmount = $filteredPayload['amountCents'] ?? $amountMinor;
        $amountMinor = is_numeric($rawAmount) ? (int) round((float) $rawAmount) : $amountMinor;
        if ($amountMinor < 0) {
            $amountMinor = 0;
        }

        $adjustedTotal = MoneyMinorUnits::fromMinor($amountMinor, $currency);
        $skipCharge = $amountMinor === 0;
        $chargeContext['total'] = $adjustedTotal;
        $chargeContext['amountCents'] = $amountMinor;
        $chargeContext['skipCharge'] = $skipCharge;
        $chargeContext = array_merge(
            $chargeContext,
            array_diff_key($filteredPayload, $chargePayload, $chargeContext)
        );

        return [
            'skipCharge' => $skipCharge,
            'total' => $adjustedTotal,
            'chargeContext' => $chargeContext,
        ];
    }

    /**
     * @param array<string, mixed> $prepared
     * @param array<string, mixed> $emptyResult
     * @return array<string, mixed>
     */
    private function skippedChargeOutcome(array $prepared, array $emptyResult): array
    {
        $chargeContext = is_array($prepared['chargeContext'] ?? null) ? $prepared['chargeContext'] : [];

        return array_merge($emptyResult, [
            'errorResponse' => null,
            'paymentResult' => [
                'success' => true,
                'transactionId' => 'ivyforms_zero_' . wp_generate_uuid4(),
                'receiptUrl' => null,
                'skipped' => true,
            ],
            'paymentTotal' => 0.0,
            'paymentCurrency' => (string) ($prepared['currency'] ?? 'USD'),
            'paymentMethod' => (string) ($prepared['gatewayId'] ?? ''),
            'chargeContext' => $chargeContext,
        ]);
    }

    /**
     * @param array<string, mixed> $emptyResult
     * @param array<string, mixed> $chargeContext
     * @return array<string, mixed>
     */
    private function failedAmountOutcome(array $emptyResult, array $chargeContext): array
    {
        return array_merge($emptyResult, [
            'errorResponse' => $this->paymentErrorResponse(
                BackendStrings::getPaymentStrings()['invalid_payment_amount']
            ),
            'chargeContext' => $chargeContext,
        ]);
    }

    private function paymentErrorResponse(string $message): WP_REST_Response
    {
        return new WP_REST_Response([
            'message' => $message,
            'data' => [
                'success' => false,
                'payment_error' => true,
            ],
        ], 400);
    }
}
