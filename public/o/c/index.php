<?php
// Variant C - free build, then $50/month hosting: same money as B. NO builder -
// the visitor fills in a brief and a human designs it. B vs C is therefore a
// clean builder test, same price, only the experience differs. Because there is
// no builder there is no job token, which is why /api/offer_checkout.php treats
// every tokenless checkout as cell C.
// Unlisted price test. See /o/_offer.php for the rationale.
$WW_VARIANT = 'c';
require __DIR__ . '/../_offer.php';
