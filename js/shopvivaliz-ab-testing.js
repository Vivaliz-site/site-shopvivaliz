/**
 * ShopVivaliz A/B Testing Framework
 * Runs conversion rate optimization tests
 * Integrates with Google Tag Manager for tracking
 */

(function() {
    'use strict';

    // A/B Testing Configuration
    const ABTestConfig = {
        experiments: [
            {
                id: 'cta-button-color-v1',
                name: 'CTA Button Color Test',
                variants: ['control-green', 'variant-orange'],
                allocation: 50, // 50/50 split
                active: true,
                startDate: '2026-07-19',
                endDate: '2026-08-19',
                metrics: ['clicks', 'conversions', 'cart_add']
            },
            {
                id: 'hero-headline-v1',
                name: 'Hero Headline Copy Test',
                variants: ['control-original', 'variant-benefit-focused'],
                allocation: 50,
                active: true,
                startDate: '2026-07-19',
                endDate: '2026-08-19',
                metrics: ['scroll_depth', 'ctr', 'time_on_page']
            },
            {
                id: 'cupom-discount-v1',
                name: 'Coupon Discount Level Test',
                variants: ['control-10percent', 'variant-15percent'],
                allocation: 50,
                active: true,
                startDate: '2026-07-19',
                endDate: '2026-08-19',
                metrics: ['redemption_rate', 'avg_order_value', 'conversions']
            },
            {
                id: 'newsletter-cta-v1',
                name: 'Newsletter CTA Placement Test',
                variants: ['control-after-hero', 'variant-after-products'],
                allocation: 50,
                active: true,
                startDate: '2026-07-19',
                endDate: '2026-08-19',
                metrics: ['signups', 'ctr', 'time_to_signup']
            }
        ],

        // Initialize A/B Testing
        init: function() {
            console.log('[ABTest] Initializing A/B Testing Framework');

            // Check for existing variant assignment
            let variants = this.getStoredVariants();

            if (!variants || Object.keys(variants).length === 0) {
                variants = this.assignVariants();
                this.storeVariants(variants);
            }

            this.applyVariants(variants);
            this.trackUserJourney();
            return variants;
        },

        // Assign random variant for each active experiment
        assignVariants: function() {
            const variants = {};

            this.experiments.forEach(exp => {
                if (!exp.active) return;

                const randomNum = Math.random() * 100;
                const variantIndex = randomNum < exp.allocation ? 0 : 1;
                variants[exp.id] = {
                    name: exp.variants[variantIndex],
                    assigned_at: new Date().toISOString(),
                    experiment: exp.name
                };

                console.log(`[ABTest] ${exp.name}: ${exp.variants[variantIndex]}`);
            });

            return variants;
        },

        // Store variant assignments in localStorage
        storeVariants: function(variants) {
            try {
                localStorage.setItem('sv_ab_variants', JSON.stringify(variants));
            } catch (e) {
                console.warn('[ABTest] Could not store variants:', e);
            }
        },

        // Retrieve stored variant assignments
        getStoredVariants: function() {
            try {
                const stored = localStorage.getItem('sv_ab_variants');
                return stored ? JSON.parse(stored) : null;
            } catch (e) {
                console.warn('[ABTest] Could not retrieve variants:', e);
                return null;
            }
        },

        // Apply variant styles and content changes
        applyVariants: function(variants) {
            // CTA Button Color Test
            if (variants['cta-button-color-v1']?.name === 'variant-orange') {
                this.applyCTAVariant('orange');
            }

            // Hero Headline Test
            if (variants['hero-headline-v1']?.name === 'variant-benefit-focused') {
                this.applyHeadlineVariant();
            }

            // Coupon Discount Test
            if (variants['cupom-discount-v1']?.name === 'variant-15percent') {
                this.applyCouponVariant('15');
            }

            // Newsletter CTA Test
            if (variants['newsletter-cta-v1']?.name === 'variant-after-products') {
                this.applyNewsletterVariant();
            }
        },

        // Apply CTA button color variant
        applyCTAVariant: function(color) {
            const style = document.createElement('style');
            if (color === 'orange') {
                style.textContent = `
                    .btn-cta-green { background: #f59e0b !important; }
                    .btn-cta-green:hover { box-shadow: 0 12px 30px rgba(245, 158, 11, 0.5) !important; }
                    .banner-cta-button { background: #f59e0b !important; box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4) !important; }
                `;
            }
            document.head.appendChild(style);
            console.log('[ABTest] CTA Variant Applied:', color);
        },

        // Apply hero headline variant
        applyHeadlineVariant: function() {
            const h1 = document.querySelector('h1');
            if (h1) {
                const originalText = h1.textContent;
                h1.textContent = 'Qualidade garantida, preços justos para sua casa';
                console.log('[ABTest] Headline Variant Applied');
            }
        },

        // Apply coupon discount variant
        applyCouponVariant: function(discount) {
            const cupomCode = 'PRIMEIRA' + discount;
            const cupomElements = document.querySelectorAll('[data-cupom], .cupom-code');
            cupomElements.forEach(el => {
                el.textContent = cupomCode;
                el.setAttribute('data-discount', discount);
            });
            console.log('[ABTest] Coupon Variant Applied:', cupomCode);
        },

        // Apply newsletter CTA variant
        applyNewsletterVariant: function() {
            // This would involve repositioning the newsletter form
            // Implementation depends on DOM structure
            console.log('[ABTest] Newsletter Variant Applied');
        },

        // Track user journey and conversion events
        trackUserJourney: function() {
            const variants = this.getStoredVariants();

            // Send variant assignment to GTM
            if (window.dataLayer) {
                window.dataLayer.push({
                    'event': 'ab_test_assigned',
                    'experiments': variants
                });
            }

            // Track conversion events
            this.setupConversionTracking();
        },

        // Setup conversion event tracking
        setupConversionTracking: function() {
            // Track "Add to Cart" clicks
            document.addEventListener('click', (e) => {
                if (e.target.matches('[data-action="add-to-cart"], .btn-add-cart')) {
                    this.trackEvent('add_to_cart');
                }
            });

            // Track CTA clicks
            document.addEventListener('click', (e) => {
                if (e.target.matches('.btn-cta-green, .banner-cta-button, .btn-primary')) {
                    this.trackEvent('cta_click');
                }
            });

            // Track Newsletter signup
            const newsletterForm = document.querySelector('.newsletter-form');
            if (newsletterForm) {
                newsletterForm.addEventListener('submit', () => {
                    this.trackEvent('newsletter_signup');
                });
            }
        },

        // Send event to GTM
        trackEvent: function(eventName) {
            if (window.dataLayer) {
                const variants = this.getStoredVariants();
                window.dataLayer.push({
                    'event': eventName,
                    'ab_variants': variants
                });
            }
            console.log('[ABTest] Event Tracked:', eventName);
        },

        // Get experiment results (for dashboard)
        getResults: function() {
            return {
                experiments: this.experiments,
                userVariants: this.getStoredVariants(),
                timestamp: new Date().toISOString()
            };
        }
    };

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            ABTestConfig.init();
        });
    } else {
        ABTestConfig.init();
    }

    // Expose globally for debugging
    window.ShopVivalizABTest = ABTestConfig;
    console.log('[ABTest] Framework loaded. Access via window.ShopVivalizABTest');
})();
