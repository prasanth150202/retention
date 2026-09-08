<?php
/**
 * Event type codes stored in events.event_type.
 *
 * FROZEN. These numbers are written into every stored row, so they may be
 * appended to but never renumbered or reused. Mirrors the map documented in
 * db/migrations/002_events_shard.sql.
 *
 * The two feeds occupy disjoint ranges on purpose. The Shopify pixel owns
 * 1-63 and the Liquid snippet owns 64+, so the Liquid feed can never be
 * mistaken for a funnel event and double-count a step. See TECHNICAL_PLAN.md
 * section 5.2.
 */

declare(strict_types=1);

final class EventType
{
    // --- Source ------------------------------------------------------
    public const SOURCE_PIXEL  = 1;
    public const SOURCE_LIQUID = 2;

    // --- Shopify Web Pixel (source = 1) ------------------------------
    public const PAGE_VIEWED               = 1;
    public const COLLECTION_VIEWED         = 2;
    public const PRODUCT_VIEWED            = 3;
    public const SEARCH_SUBMITTED          = 4;
    public const CART_VIEWED               = 5;
    public const PRODUCT_ADDED_TO_CART     = 6;
    public const PRODUCT_REMOVED_FROM_CART = 7;
    public const CHECKOUT_STARTED          = 8;
    public const CHECKOUT_CONTACT_INFO     = 9;
    public const CHECKOUT_ADDRESS_INFO     = 10;
    public const CHECKOUT_SHIPPING_INFO    = 11;
    public const PAYMENT_INFO_SUBMITTED    = 12;
    public const CHECKOUT_COMPLETED        = 13;
    public const CLICKED                   = 14;
    public const FORM_SUBMITTED            = 15;

    // --- Liquid snippet (source = 2) ---------------------------------
    public const IDENTIFY        = 64;
    public const LINK_CLICK      = 65;
    public const INTERNAL_SEARCH = 66;

    /**
     * Shopify pixel event names we subscribe to.
     *
     * input_changed / input_blurred / input_focused / alert_displayed are
     * deliberately absent: they carry raw element.value, which leaked 2,626
     * emails and roughly 30,000 phone numbers in the earlier raw export.
     * Adding them here would start storing PII. See section 5.1.
     */
    private const PIXEL_NAMES = [
        'page_viewed'                       => self::PAGE_VIEWED,
        'collection_viewed'                 => self::COLLECTION_VIEWED,
        'product_viewed'                    => self::PRODUCT_VIEWED,
        'search_submitted'                  => self::SEARCH_SUBMITTED,
        'cart_viewed'                       => self::CART_VIEWED,
        'product_added_to_cart'             => self::PRODUCT_ADDED_TO_CART,
        'product_removed_from_cart'         => self::PRODUCT_REMOVED_FROM_CART,
        'checkout_started'                  => self::CHECKOUT_STARTED,
        'checkout_contact_info_submitted'   => self::CHECKOUT_CONTACT_INFO,
        'checkout_address_info_submitted'   => self::CHECKOUT_ADDRESS_INFO,
        'checkout_shipping_info_submitted'  => self::CHECKOUT_SHIPPING_INFO,
        'payment_info_submitted'            => self::PAYMENT_INFO_SUBMITTED,
        'checkout_completed'                => self::CHECKOUT_COMPLETED,
        'clicked'                           => self::CLICKED,
        'form_submitted'                    => self::FORM_SUBMITTED,
    ];

    private const LIQUID_NAMES = [
        'identify'        => self::IDENTIFY,
        'link_click'      => self::LINK_CLICK,
        'internal_search' => self::INTERNAL_SEARCH,
    ];

    /** Funnel steps, in order. Keys are rollup_daily_funnel.step. */
    public const FUNNEL_STEPS = [
        1 => self::PAGE_VIEWED,
        2 => self::PRODUCT_VIEWED,
        3 => self::PRODUCT_ADDED_TO_CART,
        4 => self::CHECKOUT_STARTED,
        5 => self::PAYMENT_INFO_SUBMITTED,
        6 => self::CHECKOUT_COMPLETED,
    ];

    /**
     * Checkout micro-funnel stages, keys are rollup_daily_abandon.stage.
     *
     * Stages 3-6 are invisible to Shopify's own abandoned-checkout records,
     * which only begin once contact info is submitted. That gap is the point
     * of this breakdown - see section 12.2.
     */
    public const ABANDON_STAGES = [
        2 => self::CHECKOUT_STARTED,
        3 => self::CHECKOUT_CONTACT_INFO,
        4 => self::CHECKOUT_ADDRESS_INFO,
        5 => self::CHECKOUT_SHIPPING_INFO,
        6 => self::PAYMENT_INFO_SUBMITTED,
        7 => self::CHECKOUT_COMPLETED,
    ];

    /** Resolve an incoming event name to a stored code, or null if not subscribed. */
    public static function fromName(string $name, int $source): ?int
    {
        $name = strtolower(trim($name));

        return $source === self::SOURCE_LIQUID
            ? (self::LIQUID_NAMES[$name] ?? null)
            : (self::PIXEL_NAMES[$name] ?? null);
    }

    /** Human-readable name for a stored code. */
    public static function name(int $type): string
    {
        static $flipped = null;
        if ($flipped === null) {
            $flipped = array_flip(self::PIXEL_NAMES + self::LIQUID_NAMES);
        }
        return $flipped[$type] ?? "unknown({$type})";
    }

    /** True for the event types that make up the purchase funnel. */
    public static function isFunnelStep(int $type): bool
    {
        return in_array($type, self::FUNNEL_STEPS, true);
    }
}
