<?php

return [
    // Generic
    'server_error' => 'Something went wrong. Please try again.',
    'not_found' => 'The requested item was not found.',
    'forbidden' => 'You do not have access to this area.',
    'unauthenticated' => 'Please sign in to continue.',
    'validation_failed' => 'The submitted data is not valid.',
    'rate_limited' => 'Too many requests. Please wait a moment.',
    'http_error' => 'The request could not be processed.',
    'too_many_attempts' => 'Too many attempts. Please wait a moment.',
    'domain_error' => 'This action cannot be completed.',

    'unreadable_response' => 'The server response could not be read.',

    // Authentication
    'invalid_mobile' => 'That mobile number is not valid.',
    'invalid_credentials' => 'The mobile number or password is incorrect.',
    'otp_not_found' => 'The verification code was not found or has expired.',
    'otp_invalid' => 'The verification code is incorrect.',
    'otp_attempts_exceeded' => 'Too many attempts. Please request a new code.',
    'otp_cooldown' => 'Please wait before requesting another code.',
    'otp_daily_limit' => 'You have reached the daily limit for verification codes.',
    'account_suspended' => 'Your account has been suspended.',
    'account_deleted' => 'This account has been deleted.',
    'client_not_permitted' => 'You are not permitted to use this app.',
    'unknown_city' => 'The selected city was not found.',
    'city_inactive' => 'The service is not active in this city.',
    'city_not_permitted' => 'You do not have access to this city’s data.',

    // QR
    'qr_malformed' => 'The QR code is not valid.',
    'qr_unsupported_version' => 'This QR code version is not supported. Please update the app.',
    'qr_expired' => 'The QR code has expired. Please scan again.',
    'qr_invalid_signature' => 'The QR code is not valid.',
    'qr_replayed' => 'This code has already been used. Please scan the new one.',
    'qr_unknown_code' => 'This code is not registered in the system.',
    'qr_revoked' => 'This code has been revoked. Please contact support.',

    // Wallet & payment
    'insufficient_funds' => 'Your wallet balance is not sufficient.',
    'wallet_frozen' => 'Your wallet is frozen.',
    'wallet_closed' => 'The wallet has been closed.',
    'wallet_not_found' => 'The wallet was not found.',
    'amount_must_be_positive' => 'The amount must be greater than zero.',
    'topup_below_minimum' => 'The top-up amount is below the minimum allowed.',
    'topup_above_maximum' => 'The top-up amount is above the maximum allowed.',
    'wallet_balance_limit_exceeded' => 'This would take the wallet over its balance limit.',
    'daily_spend_limit_exceeded' => 'You have reached your daily spending limit.',
    'currency_mismatch' => 'The transaction currency does not match the wallet.',
    'posting_not_balanced' => 'The posting does not balance.',
    'posting_has_no_lines' => 'The posting has no ledger lines.',
    'ledger_is_immutable' => 'Ledger entries cannot be changed.',
    'transaction_not_reversible' => 'This transaction cannot be reversed.',
    'transaction_already_reversed' => 'This transaction has already been reversed.',
    'transaction_not_refundable' => 'This transaction cannot be refunded.',
    'transaction_already_refunded' => 'This transaction has already been refunded.',
    'transaction_already_settled' => 'This transaction has already been settled.',
    'payment_amount_mismatch' => 'The paid amount does not match the requested amount.',
    'gateway_unavailable' => 'The payment gateway is unavailable.',
    'invalid_commission' => 'The calculated commission is not valid.',
    'no_fare_rule_configured' => 'No fare rule is configured for this line.',

    // Boarding
    'no_active_trip' => 'This bus has no active trip right now.',
    'trip_not_accepting_boarding' => 'Boarding is not possible in the trip’s current state.',
    'already_on_this_bus' => 'You are already aboard this bus.',
    'ride_already_in_progress' => 'You have an open ride. Please end it first.',
    'reboard_cooldown' => 'You boarded this bus a moment ago.',
    'too_far_from_bus' => 'You are too far from the bus.',

    // Driver & trips
    'not_a_driver' => 'Your account is not a driver account.',
    'driver_not_active' => 'Your driver account is not active.',
    'license_expired' => 'Your driving licence has expired.',
    'contract_ended' => 'Your contract has ended.',
    'city_mismatch' => 'This bus belongs to a different city.',
    'bus_not_assigned' => 'This bus is not assigned to you.',
    'bus_not_deployable' => 'This bus is not ready for service.',
    'bus_already_in_service' => 'Another driver has an open shift on this bus.',
    'driver_already_on_shift' => 'You have an open shift on another bus.',
    'bus_already_on_trip' => 'This bus is already on an active trip.',
    'no_open_shift' => 'There is no open shift.',
    'shift_not_open' => 'The shift is not open.',
    'line_not_active' => 'This line is not active.',
    'invalid_trip_transition' => 'That trip status change is not allowed here.',
    'trip_not_accepting_telemetry' => 'Location reports are not accepted for this trip.',
    'eta_unavailable' => 'An arrival time cannot be calculated.',

    // GPS
    'gps_accuracy_too_low' => 'The location accuracy is not sufficient.',
    'gps_speed_implausible' => 'The reported speed is implausible.',
    'gps_timestamp_in_future' => 'Your device clock is not set correctly.',
    'gps_timestamp_too_old' => 'The location report is too old.',
    'gps_jump_detected' => 'An impossible jump in position was detected.',
    'invalid_coordinate' => 'The coordinates are not valid.',

    // Merchant
    'not_merchant_staff' => 'You are not staff at any merchant.',
    'merchant_not_active' => 'This merchant is not active.',
    'refund_not_permitted' => 'You are not permitted to issue refunds.',
    'reports_not_permitted' => 'You do not have access to reports.',
    'settlement_not_permitted' => 'Only a merchant manager can request a settlement.',
    'merchant_refunds_disabled' => 'Refunds are disabled for this merchant.',
    'amount_above_merchant_limit' => 'The amount is above this merchant’s limit.',
    'nothing_to_settle' => 'There are no unsettled transactions in this period.',
    'settlement_not_approvable' => 'This settlement cannot be approved.',
    'settlement_below_minimum' => 'The settlement amount is below the minimum.',
    'settlement_not_approved' => 'The settlement must be approved first.',
    'settlement_already_paid' => 'This settlement has already been paid.',

    // Import
    'import_file_unreadable' => 'The import file could not be read.',
    'import_file_empty' => 'The import file has no data rows.',

    // Complaints
    'complaint_closed' => 'This complaint is closed.',
    'complaint_not_rateable' => 'This complaint cannot be rated.',
    'too_many_attachments' => 'Too many attachments.',
];
