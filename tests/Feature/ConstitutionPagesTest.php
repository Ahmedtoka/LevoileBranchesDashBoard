<?php

namespace Tests\Feature;

use Tests\Support\Seeds;
use Tests\TestCase;

class ConstitutionPagesTest extends TestCase
{
    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/constitution/department-blueprint.standalone.html')->assertRedirect('/login');
        $this->get('/constitution/evidence/lp-sop.html')->assertRedirect('/login');
    }

    public function test_logged_in_user_opens_the_page_and_only_listed_pages(): void
    {
        $user = Seeds::user('super_admin');

        $this->actingAs($user)->get('/constitution/department-blueprint.standalone.html')->assertOk();
        $this->actingAs($user)->get('/constitution/README.md')->assertNotFound();
    }

    public function test_evidence_rejects_paths_and_missing_files(): void
    {
        $user = Seeds::user('super_admin');

        $this->actingAs($user)->get('/constitution/evidence/..%2F..%2F..%2F.env')->assertNotFound();
        $this->actingAs($user)->get('/constitution/evidence/no-such-file.pdf')->assertNotFound();
    }
}
