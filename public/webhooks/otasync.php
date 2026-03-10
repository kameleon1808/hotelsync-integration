<?php

/**
 * public/webhooks/otasync.php
 *
 * Inbound webhook endpoint for HotelSync (OTASync) events.
 * Receives HTTP POST requests, validates the payload, and stores
 * raw events in the webhook_events table for async processing.
 *
 * NOTE: This file is a Phase 5 placeholder. Full implementation
 *       is part of Phase 5 – Webhook Handling.
 */

http_response_code(503);
echo json_encode(['status' => 'not_implemented', 'message' => 'Webhook endpoint – Phase 5']);
