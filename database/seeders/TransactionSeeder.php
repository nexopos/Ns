<?php

namespace Database\Seeders;

use Ns\Models\TransactionAccount;
use Ns\Models\TransactionHistory;
use Ns\Models\User;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $author = User::get()->map( fn( $user ) => $user->id )
            ->shuffle()
            ->first();

        $group = new TransactionAccount;
        $group->name = 'Exploitation Expenses';
        $group->account = '000010';
        $group->operation = TransactionHistory::OPERATION_DEBIT;
        $group->author = $author;
        $group->save();

        $group = new TransactionAccount;
        $group->name = 'Employee Salaries';
        $group->account = '000011';
        $group->operation = TransactionHistory::OPERATION_DEBIT;
        $group->author = $author;
        $group->save();

        $group = new TransactionAccount;
        $group->name = 'Random Expenses';
        $group->account = '000012';
        $group->operation = TransactionHistory::OPERATION_DEBIT;
        $group->author = $author;
        $group->save();
    }
}
