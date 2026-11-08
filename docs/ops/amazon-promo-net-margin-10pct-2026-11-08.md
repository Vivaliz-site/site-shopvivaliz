# Amazon promotion audit — 2026-11-08, 10% net margin floor

## Evidence from real Amazon and Olist API calls

- Amazon Sales: November 2025 through October 2026, 6,377 orders and BRL 557,894.84; March-to-April orders declined 46.5%; July-to-August 38.0%.
- Prior margin snapshot: 527 Seller SKUs, 454 BUYABLE, 288 with projected current margin >= 10%; estimates are not financial certifications.
- For 21 Seller SKUs screened for 5% coupon: 21/21 current selling prices verified through Amazon Pricing; 21/21 current selling fee quotes and 21/21 5%-reduced price fee quotes obtained from official Amazon Product Fees API.
- Independently validated 9/9 ERP products: active products, last positive historical cost matching the snapshot, and available stock >0 through Olist v3.
- Amazon FBA Onsite inventory: 14/14 selected Seller SKUs have positive fulfillable quantities.
- Private evidence in Marina runtime directory /home/ubuntu/.shopvivaliz/runtime/amazon-margin/ : live-promo-fee-quotes-20261108.json, erp-promo-21-live-20261108.json, fba-promo-inventory-20261108.json. Never publish raw account data or tokens.

## Critical ASIN-level coupon constraint

The 21 candidate SKUs represent 14 ASINs. Coupons are created against ASINs; every BUYABLE listing from the account attached to the ASIN must be considered.

- ASIN B07DKZZWKF: the prequalified Seller SKU R9-O6PX-YBXA shares an ASIN with VW-OD1U-AW2Y, whose conservative projected margin after 5% is only 1.81%.
- ASIN B09L3RDSV6: the prequalified Seller SKU Ved80dba shares an ASIN with PP-SXI4-NSZS and VED80FBA, each about 5.26% after 5%.
- ASIN B0GCW9MCSX: sibling SKU 3D-NFN5-3SGL would be about 7.62% after 5%.
- Other ASINs have siblings with unverified financial identity, fulfillment program or kit composition. Only B0BYFCT9VJ and B0GCWWKQ67 had no such sibling risk in the prior snapshot.
- ERP product 342850165 has 134 available units, while the sum of quantities reported as fulfillable across five Amazon FBA listings equals 158. This is potential inventory exposure, not proof of physical overselling; reconcile before promoting B0BYFCT9VJ.
- ASIN B0GCWWKQ67: two FBA listings (8G-YWOK-H8XL, Ved-10cmt) each have only BRL 0.69 of incremental per-unit expenditure remaining above the 10% floor after a simulated 5% coupon, BEFORE coupon redemption fees and ads.

## Provider and safety gates

Seller Central Brazil coupon requirements include 5% minimum discount, review/rating and reference-price rules. The seller pays the coupon discount AND a redemption fee. Amazon states coupon budget is not a hard spending cap. Some coupon and price/deal promotions stack. Sponsored Products require Featured Offer eligibility and charge per click.

Official help:
- https://sellercentral.amazon.com.br/gp/help/external/GJHCPEFJ5JJQD52D
- https://sellercentral.amazon.com.br/gp/help/external/G363YLQW4NB5L38V
- https://sellercentral.amazon.com.br/gp/help/external/G2ZCMMUXK7VP3K6S
- https://sellercentral.amazon.com.br/help/hub/reference/external/GJ6T3TD3ULSDP9KV
- https://advertising.amazon.com/pt-br/resources/faq

Read-only calculation module: includes/amazon-margin/PromoFloorGuard.php; regression test: tests/amazon-margin/PromoFloorGuardTest.php. This pure library checks whole-ASIN SKU coverage, listing price readback, kit cost completeness, current/promotional Amazon fee quotes, tax, logistics, coupon fee, attributed CPC expense, stacking and exact integer-cent net margin. Unknown cost or unverified sibling -> HOLD; margin below 10% -> BLOCK. It is NOT connected to a promotion publisher and never changes prices, promotions, inventory or ads.

Remaining: verify authenticated Seller Central coupon redemption fee, eligibility and existing deals; reconcile Seller Flex inventory duplicate exposure; confirm ads and full incremental CPA; validate every ASIN-linked Seller SKU and effective promotion combinations before launch, with UI readback.

Verdict: NAO_APTO_PARA_PUBLICAR_PROMOCOES. No commercial writes executed.
