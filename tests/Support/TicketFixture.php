<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

trait TicketFixture
{
    protected function ticketFixture(): object
    {
        $this->role('administrator');
        $tenant = $this->pair['a']['tenant_id'];
        DB::selectOne("SELECT set_config('app.user_id', ?, true)", [$this->pair['a']['user_id']]);
        $client = DB::table('clients')->insertGetId(['tenant_id' => $tenant, 'name' => 'Synthetic client', 'client_type' => 'business']);
        $contact = DB::table('contacts')->insertGetId(['tenant_id' => $tenant, 'client_id' => $client, 'name' => 'Synthetic contact', 'phone' => '555-0100', 'is_primary' => true]);
        $ticket = DB::table('tickets')->insertGetId(['tenant_id' => $tenant, 'client_id' => $client, 'contact_id' => $contact, 'created_by_user_id' => $this->pair['a']['user_id'], 'subject' => 'Synthetic issue', 'description' => 'Synthetic description']);

        return DB::table('tickets')->where('id', $ticket)->first();
    }
}
