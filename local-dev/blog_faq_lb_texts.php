<?php
// Lebazone: starting blog posts + FAQs (used by setup_blog_faq.feature.php).
// Edit the live ones in admin -> Blogs and admin -> FAQs.
return [
'categories' => [
    'guides'        => 'Guides',
    'kitchen'       => 'Kitchen',
    'home-living'   => 'Home & Living',
    'personal-care' => 'Personal Care',
],
'blogs' => [
[
 'slug' => 'how-ordering-on-lebazone-works', 'cat' => 'guides', 'image' => 'lebazone-blog-howto.jpg',
 'products' => ['Air Fryer', 'Vacum Cleaner', 'LED Desk Lamp'],
 'title' => 'How ordering on Lebazone works: delivery, payment and returns',
 'short' => 'Everything you need to know before your first order - from checkout to your door, and what to do if something is not right.',
 'body' => <<<'H'
<p>New to Lebazone? Here is how it works, step by step, so your first order is simple.</p>
<h2>1. Create your account</h2>
<p>Sign up with your email (or continue with Google). Anything you put in your cart before signing up stays in your cart once your account is ready.</p>
<h2>2. Add your address</h2>
<p>Add your address and drop the pin on the map. The map tells you right away whether we deliver there - we deliver across Lebanon. Your name and number are saved with the address, so next time checkout is just one tap.</p>
<h2>3. Check out and pay</h2>
<ul>
<li><b>Cash on delivery:</b> pay the rider when your order arrives. Prices are in US dollars; the Lebanese pound amount is shown for reference.</li>
<li><b>Online:</b> the online payment options available at checkout.</li>
<li><b>Wallet:</b> refunds and credits sit in your Lebazone wallet and can be used on any order.</li>
</ul>
<p>The delivery fee is shown before you confirm, and orders above the free-delivery amount shown in your cart ship for free.</p>
<h2>4. Track your order</h2>
<p>You get an email with your invoice when you order, and you can follow every step in <b>My Orders</b>. Most orders arrive within 1 to 3 working days. Our rider will contact you before arriving.</p>
<h2>5. Changed your mind?</h2>
<p>You can cancel for free from <b>My Orders</b> until your order is packed. After delivery, most items can be returned within 7 days if unused and in the original packaging. If something arrives damaged or wrong, tell us within 3 days and we will fix it at no cost to you.</p>
<p>Full details are in our Shipping, Return and Cancellation policies at the bottom of every page. Happy shopping!</p>
H],
[
 'slug' => 'choosing-the-right-heater-for-winter', 'cat' => 'home-living', 'image' => 'lebazone-blog-heaters.jpg',
 'products' => ['Convector Heater', 'Fan Heater', 'Electric Brazier Heater'],
 'title' => 'Winter is coming: how to choose the right electric heater',
 'short' => 'Convector, fan heater or electric brazier? A quick guide to warming each room safely - and without wasting electricity.',
 'body' => <<<'H'
<p>Cold evenings are back. Picking the right heater for the right room keeps you warm and keeps your electricity (and generator) bill under control.</p>
<h2>Fan heater - fast heat for small rooms</h2>
<p>A fan heater blows warm air and heats a small room in minutes. It is perfect for a bathroom before a shower, a small bedroom or a home office. It is light and easy to carry from room to room.</p>
<h2>Convector heater - quiet, even warmth</h2>
<p>A convector warms the air slowly and spreads the heat evenly, with no fan noise. It is ideal for bedrooms and living rooms where you want steady warmth for hours, and many have a thermostat that keeps the temperature you choose.</p>
<h2>Electric brazier - the cosy, traditional feel</h2>
<p>An electric brazier gives the warm glow of a traditional kanoun without charcoal, smoke or fumes. It is great for sitting areas where the family gathers.</p>
<h2>How much power do you need?</h2>
<ul>
<li>Small room or bathroom: around 1000-1500 W.</li>
<li>Medium bedroom or office: 1500-2000 W.</li>
<li>Living room: 2000 W or more, or two heaters in different spots.</li>
</ul>
<p>On a generator subscription, check how many amps you have: a 2000 W heater uses roughly 9 amps on its own.</p>
<h2>Safety first</h2>
<ul>
<li>Keep heaters at least one metre from curtains, beds and sofas.</li>
<li>Plug them straight into the wall, not into an extension lead.</li>
<li>Choose models with overheat protection and a tip-over switch.</li>
<li>Switch off when you leave the room or go to sleep.</li>
</ul>
<p>See convector heaters, fan heaters and electric braziers in our Home &amp; Living section.</p>
H],
[
 'slug' => 'kitchen-appliances-that-save-you-time', 'cat' => 'kitchen', 'image' => 'lebazone-blog-kitchen.jpg',
 'products' => ['Air Fryer', '3 In 1 Kitchen Machine', 'Jug Blender'],
 'title' => '5 kitchen appliances that save you time every day',
 'short' => 'Less oil, less chopping, less waiting. These everyday helpers give you back time in the kitchen.',
 'body' => <<<'H'
<p>Cooking at home is cheaper and healthier - but it takes time. The right appliances do the slow parts for you.</p>
<h2>1. Air fryer</h2>
<p>Crispy fries, chicken wings and vegetables with little or no oil. It heats up in a few minutes, so it is often faster than the oven - and the basket is easy to clean.</p>
<h2>2. 3-in-1 kitchen machine</h2>
<p>One machine that kneads dough, mixes cakes and whips cream. Start the dough, walk away, and come back when it is ready.</p>
<h2>3. Blender</h2>
<p>Smoothies, soups, sauces and hummus in a minute. A jug blender is best for bigger amounts; a hand blender is great for soups straight in the pot.</p>
<h2>4. Electric juicer</h2>
<p>Fresh orange or lemon juice every morning without squeezing by hand - quick, and much less mess.</p>
<h2>5. Vacuum sealer</h2>
<p>Seal meat, cheese and leftovers so they last longer in the fridge and freezer. Cook once, store in portions, and save time all week.</p>
<h2>Tips before you buy</h2>
<ul>
<li>Check the power (watts) - more power usually means faster results.</li>
<li>Measure your counter and cupboard space.</li>
<li>Look for removable parts that are easy to wash.</li>
<li>Check the warranty shown on the product page.</li>
</ul>
<p>See all our kitchen appliances in the Home &amp; Living section.</p>
H],
[
 'slug' => 'keep-insects-out-window-and-door-screens', 'cat' => 'home-living', 'image' => 'lebazone-blog-screens.jpg',
 'products' => ['Magnetic  Door Screen', 'Aluminum Window Insect Screen'],
 'title' => 'Keep insects out the easy way: window and door screens',
 'short' => 'Open windows, fresh air and no mosquitoes. How to choose and fit the right screen for every window and door.',
 'body' => <<<'H'
<p>In Lebanon's warm months you want the windows open - but not the mosquitoes and flies that come with them. Screens are the simplest fix: no sprays, no smell, and they work day and night.</p>
<h2>Window screens</h2>
<p><b>Fixed or aluminium-frame screens</b> are strong and last for years - best for windows you open often. <b>Adhesive (stick-on) screens</b> are cheap and quick to fit - great for renters, since they come off without damage.</p>
<h2>Door screens</h2>
<p><b>Magnetic door screens</b> close by themselves after you walk through - perfect for balconies and kitchen doors, and easy for kids and pets. They fix with tape or pins, no drilling.</p>
<h2>How to measure</h2>
<ol>
<li>Measure the width and height of the opening (inside the frame) in centimetres.</li>
<li>Measure in two or three places - walls are rarely perfectly straight - and use the largest size.</li>
<li>Choose a screen the same size or slightly bigger; most can be trimmed.</li>
</ol>
<h2>Fitting tips</h2>
<ul>
<li>Clean and dry the frame well before sticking tape.</li>
<li>Fit in daylight, when it is easier to see small gaps.</li>
<li>Close every gap - mosquitoes only need a few millimetres.</li>
</ul>
<h2>Keep them working</h2>
<p>Wipe screens with a damp cloth or soft brush every few weeks, and repair small tears early before they grow.</p>
<p>Browse window insect screens and magnetic door screens in our Home &amp; Living section.</p>
H],
[
 'slug' => 'grooming-at-home-trimmers-shavers-hair-tools', 'cat' => 'personal-care', 'image' => 'lebazone-blog-grooming.jpg',
 'products' => ['Hair&Beard Trimmer', 'Rechargeable Foil Shaver', 'Hair Dryer With Touch Sensor'],
 'title' => 'Grooming at home: trimmers, shavers and hair tools explained',
 'short' => 'Trimmer or shaver? Which hair dryer? A simple guide to the right tool for a clean look at home.',
 'body' => <<<'H'
<p>A good set of grooming tools saves trips to the barber and keeps you looking sharp every day. Here is which tool does what.</p>
<h2>Hair clipper</h2>
<p>For cutting hair on the head. It comes with guide combs for different lengths - from a very short fade to a longer cut. Choose one with several combs and a strong motor that does not pull.</p>
<h2>Hair &amp; beard trimmer</h2>
<p>For shaping a beard, cleaning the neckline and keeping stubble at an exact length. Adjustable length settings are the key feature to look for.</p>
<h2>Foil shaver</h2>
<p>For a clean, close shave without a razor and foam. Rechargeable foil shavers are gentle on the skin and great for quick daily shaves.</p>
<h2>Nose &amp; ear trimmer</h2>
<p>A small, safe trimmer for the details - no pulling, no cuts.</p>
<h2>Hair dryer and straightener</h2>
<p>For drying, look at the power (watts) and heat settings - higher power dries faster, and a cool setting protects your hair. A compact travel dryer is handy for trips. A straightener with adjustable temperature lets you use less heat on fine hair.</p>
<h2>Keep them working</h2>
<ul>
<li>Brush hair out of the blades after every use.</li>
<li>Oil clipper and trimmer blades every few weeks.</li>
<li>Charge fully before the first use, and avoid leaving them charging for days.</li>
<li>Keep tools that are not waterproof away from the shower.</li>
</ul>
<p>Find trimmers, shavers, clippers and hair dryers in our Electronics &amp; Accessories section.</p>
H],
],
'faqs' => [
 ['Which areas do you deliver to?', 'We deliver across Lebanon. When you add your address and place the pin on the map, it shows right away whether we deliver there.'],
 ['How long does delivery take?', 'Most orders arrive within 1 to 3 working days. Remote areas, holidays or road conditions can add some time. The estimated date is shown at checkout and in My Orders.'],
 ['How much is delivery?', 'The delivery fee is shown at checkout before you confirm your order. Orders above the free-delivery amount shown in your cart are delivered for free.'],
 ['How can I pay?', 'You can pay cash on delivery, use the online payment options shown at checkout, or use your Lebazone wallet balance. Prices are in US dollars; the Lebanese pound amount is shown for reference.'],
 ['Do I need an account to order?', 'You can browse and fill your cart without an account. To place the order, sign up with your email or continue with Google - your cart is kept when you sign up.'],
 ['How do I track my order?', 'Open My Orders on the website or app to see every step of your order. You also receive an email with your invoice when you order, and our rider contacts you before arriving.'],
 ['Can I cancel my order?', 'Yes - for free from My Orders until your order is packed. After that, contact us by email; if it is already on the way you can refuse it or return it under our Return Policy.'],
 ['What is your return policy?', 'Most items can be returned within 7 days of delivery if they are unused and in the original packaging with all accessories. Opened hygiene items and items marked non-returnable cannot be returned. See the Return Policy for full details.'],
 ['My item arrived damaged or wrong. What do I do?', 'Contact us within 3 days of delivery with photos or a short video. We will replace the item or refund you, and we cover the delivery cost.'],
 ['How do refunds work?', 'Approved refunds go to your Lebazone wallet right away, to use on any order. If you prefer, cash on delivery orders can be refunded in cash or by transfer, and online payments back to the original method within 7 working days.'],
 ['Do products have a warranty?', 'Products with a warranty show it on the product page. The warranty covers manufacturing faults, not misuse, water or drop damage. Email us with your order number to make a claim.'],
 ['What does "Sold by" on a product mean?', 'Some products are supplied by partner sellers and show their name under "Sold by". Lebazone still handles your order, delivery, returns and customer service, under the same policies.'],
 ['Can I sell my products on Lebazone?', 'Yes. Open "Sell on Lebazone" at the bottom of any page to tell us about your products, and our team will get back to you.'],
 ['Is my personal information safe?', 'Yes. Your password is stored encrypted, all connections are secured with HTTPS, and we never sell your information. See our Privacy Policy for details.'],
 ['How can I contact you?', 'Email us at lb.lebazone@gmail.com and we will get back to you as soon as possible.'],
],
];
