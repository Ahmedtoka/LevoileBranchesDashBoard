<?php

namespace App\Http\Requests\Observations;

/**
 * POST /observations/review/{observation}/promote: create a catalog item from the review form, then merge.
 * Same rules and stored shapes as the catalog admin form (CatalogItemRequest). Authorization in the controller.
 */
class ReviewPromoteRequest extends CatalogItemRequest
{
}
