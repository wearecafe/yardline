# Mason Supply Yard Software: Competitive Feature Research

Research done October 2026 to support building a modern inventory/ERP product for masonry and building supply yards. Sources: vendor websites, software directories, LinkedIn and trade show listings. There were no public APIs, documentation or ungated video transcripts for any of these competitors, so treat this as a starting point and confirm through demos and customer interviews.

## 1. Market Summary

- **Target customer:** masonry and building supply yards (wholesale, retail counter and producers) selling block, brick, pavers, hardscape products (Nicolock, Cambridge, etc.), stone, aggregate and bulk material.
- **Main incumbent:** CAVU ERP, a vertical ERP from an established vendor that dates back to 1990.
- **Modern challenger:** YardBoss, a cloud-based, mobile-friendly yard management SaaS with a self-serve trial.
- **Adjacent, not direct:** Crete Quote, a customer-facing estimating tool for concrete and hardscape _contractors_. It doesn't compete with yard software, but it points to a possible feature or partnership: contractor-facing quoting that feeds yard orders.

## 2. CAVU ERP (Incumbent)

**Company:** Universal Accounting Software, Inc. (now marketed as CAVU ERP Corporation). Founded in 1990. Customers across the US, Canada, Hawaii, Alaska, the Caribbean and the Philippines. Exhibits at Hardscape North America.

**Positioning:** "Ceiling and Visibility Unlimited." It's an industry-specific ERP with accounting built in, sold to 8 verticals: mason and building supply, equipment rental, trailer rental, containers and portable storage, wholesale distribution, retail, service and repair, and food distribution. Mason supply is one market among several, not its only focus.

**Deployment:** cloud (hosted on Microsoft Azure) or client/server on-premise.

**Pricing:** custom quotes only, nothing published. Sales go through demos.

### Advertised Mason Supply Features

**Point of sale (counter)**

- Fast POS built for the early-morning contractor rush
- Truck scale integration for aggregate and bulk materials
- Barcode scanning
- Driver's licence scanning
- Credit card processing with signature devices
- Back-office orders and the front counter work together seamlessly

**Sales order dashboard ("command central")**

- Quote-to-order workflow
- Drill-down view of a whole opportunity, with data pulled from every department
- Order status, related purchase orders, expected receiving dates
- Back order status for each line item
- Lost opportunity reporting

**Dispatch**

- Visual dispatch board for trucks, drivers and deliveries
- Sales orders and POS tickets show up for dispatch automatically
- Pitched as replacing whiteboards, sticky notes and spreadsheets
- Their rental product advertises built-in texting for delivery and pickup scheduling, and the mason version likely shares this

**Customer and job management**

- Instant order history by customer, job and product
- E-docs: email statements, invoices and job reports instantly
- Messaging focused on customer loyalty and repeat business

**Inventory, purchasing and pricing**

- Inventory levels update dynamically
- Order tracking and procurement visibility
- Margin management ("sell more product at the right margin"), marketed around volatile material prices
- Multi-location visibility

**Accounting**

- Full built-in accounting (GL, AR, AP), so no separate accounting package is needed

**Other modules seen in their other verticals (possibly available to mason customers)**

- Job tracking with labour time capture (barcode)
- Attaching pictures, drawings and documents to jobs
- Periodic and recurring billing
- Upsell prompts suggesting related items at order entry
- Kits and bundles

### Weaknesses and Gaps (observed or likely)

- No public API or documented third-party integrations
- No dedicated mobile app listed in directories
- Legacy architecture: a 1990s product that moved to Azure, still offered on-prem
- English only
- No published pricing and a sales-led buying process
- Almost no public user reviews (directories show zero), which means little social proof
- Spread across 8 verticals, so mason-specific depth may be limited
- Training happens through docs, live online sessions, webinars and in person, which suggests implementation is heavy

## 3. YardBoss (Modern Challenger)

**Website:** yardbosspro.com. Note that several unrelated products share the "Yard Boss" name (forestry, biomass, landscaping), so watch out for that when researching.

**Positioning:** building materials yard management software. It's cloud-based, works on a phone, and offers a 14-day free trial with no credit card.

### Advertised Features

- **Quotes:** professional line-item quotes, sent to customers, converted to orders in one click, with a quote pipeline tracked from draft to accepted
- **Order visibility:** see every order, truck and picker from a phone
- **Picking:** picker workflow, with pick efficiency tracked
- **Driver app:** mobile app for drivers with signature capture for proof of delivery. Customers say it eliminated "I never got my delivery" disputes
- **Reporting:** revenue by product, top customers, pick efficiency, delivery performance, monthly trends

### Observations

- Self-serve, product-led onboarding, which is the opposite of CAVU
- Strong on mobile operations (yard staff and drivers)
- Unclear depth on accounting, POS, truck scales and multi-location. These need confirming with a trial
- **Next step:** sign up for the free trial and document the full feature set and pricing

## 4. Crete Quote (Adjacent)

**Website:** cretequote.com

**What it is:** an estimating app for concrete (and some hardscape) contractors. A widget on the contractor's website lets homeowners design their own project and get an instant estimate around the clock.

**Features**

- Customer self-serve project builder and instant estimates
- Embeddable on the contractor's website
- Captures leads with project details for estimators
- Directories list quotes and estimates, plus pricing and cost calculations, but no billing or invoicing

**Relevance:** not a yard competitor. The interesting part is the idea: a contractor-facing portal where a yard's contractor customers can build material quotes (patio square footage turned into pavers, base, sand, edging) and send orders straight into the yard's system. Other contractor tools in this space include QuoteIQ, SatQuote and Cleri.

## 4b. Wider Landscape and Masonry Supply Yard Market

**Product assumptions (Jon):** accounting goes through QuickBooks Online, not built in. Point of sale will likely run through Shopify. Focus areas: inventory, POS, equipment rental management and client-facing features.

### FlintPOS (closest direct competitor)

[flintpos.com](https://www.flintpos.com/). Landscape supply yard software built by a landscape supply yard operator.

- **Pricing:** published. Core is $99/month after a 30-day trial with no credit card. Storefront, text messaging and Dispatch Pro are paid add-ons
- **Selling units:** mulch by the yard, stone by the ton, products by the bag or pallet or individually, all from one product record
- **Pricing tiers:** retail, contractor and wholesale pricing without duplicating products
- **Contractor accounts**
- **Delivery:** delivery pricing and tax worked out at order time, with delivery details attached to the order
- **"One live order":** the order flows from counter to yard to truck to office with no re-entry
- **Client-facing:** an online storefront and customer texting (add-ons)
- **Relevance:** the most similar positioning to Jon's idea. Its modular add-on pricing is a useful benchmark

### ECI Solutions: Spruce and RockSolid MAX (mid-market incumbent)

[ecisolutions.com](https://www.ecisolutions.com/products/building-materials-software/)

- **Spruce:** POS and ERP for lumber and building supply, plus landscape and nursery supply. Covers purchasing and inventory, multi-location, drag-and-drop load scheduling, proof of delivery, quotes that turn into work orders, e-commerce and analytics. Has an API
- **RockSolid MAX:** a lighter, cheaper POS and inventory system marketed to masonry, brick and block stores and to patio, hearth and hardscape stores. Handles large catalogues of brick, block and stone variations with real-time availability
- **Relevance:** ECI goes after the masonry and hardscape store niche directly, so it's a key competitor to benchmark

### Epicor BisTrack (enterprise incumbent)

[epicor.com/bistrack](https://www.epicor.com/en-us/products/enterprise-resource-planning-erp/bistrack/)

- **Inventory:** bulk, length-based and variable-weight materials. Yard location management, receiving, cycle counts, and moving stock between zones
- **Mobile warehouse:** pick, pack and ship with barcoding (Epicor WMS)
- **Delivery:** load planning, route scheduling, driver manifests, GPS, SMS and email delivery notifications, mobile proof of delivery with photo and signature, Trimble routing integration
- **Sales:** quotes and orders, product configurators, opportunity tracking, mobile sales tools
- **Add-ons:** AR and AP automation, tax engine, workflow automation
- **Relevance:** the feature ceiling. Too big and expensive for small yards, which leaves room for a lighter product

### POS Highway

[poshighway.com](https://www.poshighway.com/industries/garden-center-and-landscape-supply-pos-system)

- POS for garden centres, landscape supply and building supply
- Bulk material checkout and contractor orders
- Units of measure: linear feet, cubic yards, pallets, bundles, pieces
- Builder, contractor and landscaper pricing tiers, plus contract pricing for specific customers
- Special orders for non-stock items (sod, trees, specialty stone)
- Account terms at the counter. Ship-to addresses, taxes, fees and surcharges applied at POS
- Service and repair tickets, including equipment maintenance
- Syncs with QuickBooks Online and Sage. This validates the plan to integrate accounting instead of building it

### Delivery and hauling tools (adjacent)

- **Toro TMS:** transport management for bulk landscape material. Electronic weigh tickets, signatures and photos, plus QuickBooks sync
- **Transvirtual, DispatchOne:** last-mile delivery and routing that can take orders from an existing ERP
- **Relevance:** dispatch can be bought or integrated instead of built at first

### Contractor-side tools (the yard's customers)

QuoteIQ, Projul, Workhand, GreenRoute, Builder Prime, True Pro and Werx serve landscape and hardscape contractors with estimating, material takeoffs (pavers, base, sand), deposits and client portals. A yard product could integrate with these, or offer its own material calculators so contractors order straight from the yard.

### Equipment rental

None of the yard-focused tools above advertise strong equipment rental management. CAVU sells rental as a separate vertical, and POS Highway only mentions repair and service tickets. **Open question:** do landscape and mason yards rent equipment (compactors, saws, skid steers, splitters) enough to justify a built-in rental module? The next research pass should cover dedicated rental software such as Point of Rental, Booqable, EZRentOut and Rentman, plus Shopify rental apps.

### Shopify POS fit: what yards need that Shopify lacks natively

- Fractional and bulk units (tons, yards, half-pallets) and weight-based sales from truck scales
- Contractor accounts on credit terms, plus statements and AR (would sit in QuickBooks Online)
- Contractor and wholesale price tiers (partly covered by Shopify B2B, which needs Shopify Plus)
- Pallet deposits and returns
- Delivery fees by zone or distance, and dispatch
- Quotes that turn into orders
- Rental contracts, availability and deposits

These gaps are where the custom product would add value on top of Shopify.

### Equipment rental software (construction, landscape and heavy equipment only)

Rental is in scope: equipment management, pricing, notifications, agreements and e-signing. The tools below are the ones targeted at equipment rental specifically.

| Vendor                                                                                            | Target                                                     | Notable features                                                                                                                                   | Relevance                                                               |
| ------------------------------------------------------------------------------------------------- | ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| [Quipli](https://www.quipli.com/)                                                                 | Independent general-tool, light and heavy equipment rental | Contracts, availability, photos, deliveries and digital signatures from a phone; online ordering website; POS; maintenance; QuickBooks Online sync | Closest model for the rental module. Reported as pricey for small shops |
| [InTempo](https://www.intemposoftware.com/products/intempo-mobile-app) (merged with Texada, 2023) | Mid-market and enterprise                                  | Mobile quotes, reservations, e-signatures and inspections                                                                                          | Benchmark for mobile counter and yard workflows                         |
| [Texada](https://texadasoftware.com/equipment-rental-software-rental-management/)                 | Heavy construction equipment                               | Reservations turned into contracts, staging assets before pickup; rental, sales and service together                                               | Enterprise feature ceiling                                              |
| Point of Rental                                                                                   | General and construction rental stores                     | Central dashboard for inventory, billing and contracts; analytics                                                                                  | Established incumbent                                                   |
| Wynne RentalMan                                                                                   | Large heavy-equipment fleets                               | Full lifecycle from contract to equipment cost analysis                                                                                            | Enterprise only                                                         |
| Clue                                                                                              | Contractors with mixed heavy fleets                        | Telematics engine hours triggering maintenance, inspections, dispatch                                                                              | Idea: hour-meter based maintenance                                      |
| Renterra, Rentrax                                                                                 | Smaller equipment renters                                  | Conflict detection, contract creation, maintenance reminders, invoicing                                                                            | Lighter competitors to check                                            |

Sources: [rent2B guide](https://www.rent2b.net/en/blog/equipment-rental-software-guide-2026), [EZO heavy equipment roundup](https://ezo.io/ezrentout/blog/best-heavy-equipment-rental-software-compared/), [SoftwareConnect](https://softwareconnect.com/roundups/best-equipment-rental-software/).

**Takeaway:** dedicated rental tools are separate from yard POS systems. A supply yard that also rents compactors, saws or skid steers runs two systems today, which is the gap this product would close.

### Quipli deep dive (rental module benchmark)

Quipli is the model to study for the rental module: a flat $6,000 per location per year, aimed at independent equipment rental companies.

- **Pricing:** Pro plan from $6,000/year per location ([Capterra](https://www.capterra.com/p/232916/Quipli-Rental-Software/pricing/)). Unlimited users and inventory. Multi-location discounts. Free trial, no card
- **Contracts:** reservations turn into contracts. Contracts, photos and digital signatures are handled from any phone in the yard or on site, with no app download needed ([quipli.com](https://www.quipli.com/))
- **Billing:** cycle billing for long rentals, invoicing, payments by ACH, card or cheque, plus POS
- **Rates:** dynamic pricing and rate management
- **Damage waiver:** an add-on covering collision, overturn, theft, fire, flood, vandalism and weather, for equipment worth $1,000 to $500,000 ([damage waiver](https://quipli.com/solutions/equipment-rental-damage-waiver/))
- **Inventory:** unit-level asset tracking to prevent double booking, an availability calendar, and a dashboard of today's pickups, deliveries and overdue items
- **Maintenance:** scheduled maintenance, repairs, costs and planned services (added February 2023)
- **Client-facing:** an online storefront for browsing and booking equipment
- **Integrations:** QuickBooks Online sync, Zapier and CRMs. No public API reported

**Weaknesses from user reviews** ([GetApp](https://www.getapp.com/industries-software/a/quipli-rental-software/)): the QuickBooks integration is called weak and poorly documented. It's hard to see all reservations for one unit without going through the calendar. Price is steep for small shops.

**Opportunities for Jon's product:** a solid QuickBooks Online sync, an open API, a per-unit history view, rental pricing that fits a yard with a few rental units, and one system for yard sales and rentals together.

## 5. Feature Comparison

| Capability                              | CAVU ERP    | YardBoss            | Crete Quote    |
| --------------------------------------- | ----------- | ------------------- | -------------- |
| Counter POS                             | Yes         | Unconfirmed         | No             |
| Truck scale integration                 | Yes         | Unconfirmed         | No             |
| Quotes to orders                        | Yes         | Yes                 | Estimates only |
| Dispatch board                          | Yes         | Truck visibility    | No             |
| Driver mobile app and proof of delivery | Unconfirmed | Yes, with signature | No             |
| Picking workflow                        | Unconfirmed | Yes                 | No             |
| Inventory and purchasing                | Yes         | Likely              | No             |
| Built-in accounting                     | Yes         | Unconfirmed         | No             |
| Multi-location                          | Yes         | Unconfirmed         | No             |
| Public API                              | No          | Unconfirmed         | Unconfirmed    |
| Self-serve trial                        | No          | Yes, 14 days        | Unconfirmed    |
| Published pricing                       | No          | Unconfirmed         | Unconfirmed    |
| Customer self-quoting                   | No          | No                  | Yes            |

## 6. Opportunity Areas for a New Product

These are research-based hypotheses to test with yard owners.

- **Modern cloud and mobile-first:** a counter tablet, a yard picker app and a driver app as core parts of the product, not add-ons
- **Open API and integrations:** QuickBooks/Xero/Sage, card processors, truck scales, supplier price lists, e-commerce
- **Transparent pricing and fast onboarding:** a self-serve trial with data import from spreadsheets and legacy systems
- **Mason-specific depth:** pallet and deposit tracking, unit conversions (pallets, square feet, cubes, tonnes, yards), bulk material by weight, manufacturer price list updates, project and job pricing for contractors, colour and lot or batch matching for pavers
- **Contractor portal:** account balances, order history, reordering, delivery tracking, and self-serve material quoting (the Crete Quote idea, built for yards)
- **Dispatch and delivery:** route planning, live driver tracking, customer ETA texts, photo and signature proof of delivery, boom and moffett truck scheduling
- **Canadian market:** CAD and HST/GST/QST taxes, French language support for Quebec, and Canadian accounting integrations. CAVU is English-only and US-focused
- **Margin intelligence:** alerts when supplier costs change, and price-update workflows for volatile materials

## 7. Research Next Steps

- Book a CAVU ERP demo and register for any mason supply webinars
- Start a YardBoss free trial and document every screen and the pricing
- Interview 5 to 10 supply yard owners and counter staff about pain points
- Look into other systems yards use: Epicor BisTrack, ECI Spruce, DMSi Agility, and generic POS or QuickBooks setups
- Check Hardscape North America and Concrete Masonry and Hardscapes Association exhibitor lists for more competitors

## 8. Feature Scope for Mockups

Shopify records every goods sale; the app manages inventory and fulfilment for those sales and runs the whole rental business itself, including payments.

### System boundaries

| Area                                      | Shopify                               | The app                                                                                      | QuickBooks Online                    |
| ----------------------------------------- | ------------------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------ |
| Goods sales (stone, block, mulch, pavers) | Checkout, payment, transaction record | Inventory, units of measure, contractor pricing pushed to Shopify, pick/pack lists, delivery | Receives sales from Shopify          |
| Equipment rental                          | None                                  | Quotes, reservations, contracts, e-signing, payments, deposits, returns                      | Receives rental revenue from the app |
| Customers                                 | Customer records for goods sales      | Contractor accounts, rental customers, ID and insurance on file                              | Customer balances and statements     |

### Module 1: Supply yard inventory and fulfilment

- **Product catalogue:** one product, many selling units (ton, yard, bag, pallet, each) with conversions
- **Pricing:** retail, contractor and wholesale tiers plus customer-specific pricing, synced to Shopify (B2B catalogues or a POS extension) so the till charges the right price
- **Stock:** live quantities by yard location, bulk stock tracked by weight or volume, cycle counts, adjustments, colour and lot tracking for pavers and block
- **Receiving and purchasing:** purchase orders, receiving against supplier deliveries, supplier price list updates and cost-change alerts
- **Order intake from Shopify:** each Shopify order arrives in the app and becomes a pick/pack list
- **Pick and load:** a yard-staff mobile view to pick, load and confirm quantities, with truck scale weights where used
- **Delivery and dispatch:** delivery fees by zone or distance, a dispatch board for trucks and drivers, driver app with photo and signature proof of delivery, ETA texts to customers
- **Pallets:** pallet deposits charged and tracked, returns credited
- **Contractor portal:** order history, reorders, delivery tracking, material calculators

### Module 2: Equipment rental (fully in-app)

- **Fleet:** each rental unit tracked by serial number, with status (available, out, in maintenance), hour meters and photos
- **Rates:** hourly, half-day, daily, weekly and monthly rates, weekend rates, minimum charges, delivery and pickup fees, fuel and cleaning charges
- **Availability:** a calendar per unit and per category that blocks double booking
- **Booking:** counter, phone and online booking with real-time availability
- **Agreements:** contract generated from the reservation, rental terms, damage waiver option, e-signature on a phone or tablet, ID and insurance capture
- **Payments:** card on file, deposits and holds on a card, billing in repeating cycles for long rentals, payment links, refunds (a processor like Stripe)
- **Checkout and return:** pre-rental and return inspections with photos, condition notes, damage and late-return charges
- **Notifications:** booking confirmations, pickup and return reminders, overdue alerts, extension requests, invoices by text and email
- **Maintenance:** service schedules by hours or days, repair logs and costs, units locked out until serviced
- **Reporting:** usage, revenue per unit, maintenance cost per unit

### Open questions before mockups

- [ ] Contractor pricing: going with Shopify Plus B2B catalogues (assumed supported at Shopify POS)
- [ ] Does a rental customer who also buys goods get one account across both, and one statement?
- [ ] Which payment processor for rentals, and how rental revenue syncs to QuickBooks Online
- [ ] Damage waiver: built in or through a partner?
- [ ] Validate the rental module with 5 to 10 yard owners: which equipment they rent, and how often

## 9. Screens to Mock Up

The first mockup pass covers six screens per module, ordered by how often staff use them.

### Supply yard

1. **Product editor:** one product with its selling units (ton, yard, bag, pallet), conversions, and the price tiers synced to Shopify B2B
2. **Stock overview:** quantities by yard location, low-stock flags, lot and colour
3. **Order queue:** Shopify orders arriving as pick/pack lists, with status from new to picked, loaded and delivered
4. **Yard picker (mobile):** pick list, confirm quantities, scale weight, mark loaded
5. **Dispatch board:** trucks and drivers by day, drag-and-drop deliveries
6. **Contractor portal:** order history, reorder, delivery tracking

### Equipment rental

1. **Fleet list:** units with status, hour meter and next service
2. **Availability calendar:** units by day, bookings and conflicts
3. **New rental:** customer, unit, dates, rate calculation, deposit, damage waiver
4. **Agreement and signing (tablet):** terms, ID and insurance capture, signature, card on file
5. **Check-out and return inspection (mobile):** photos, condition notes, hours, damage and late charges
6. **Customer booking page:** browse equipment, check availability, book and pay online
