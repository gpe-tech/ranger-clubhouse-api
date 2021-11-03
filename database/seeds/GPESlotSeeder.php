<?php

use Illuminate\Database\Seeder;

class GPESlotSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     *
     * GPE Edition: IDs start at 1000
     *
     * @return void
     */
    public function run()
    {
      // Copied from EventDatesTableSeeder - could use some constants but copy-paste
      // rules the day while trying to GTD - refactor this someday, dear someone else.
      $seed_event_date = strtotime('last monday');

      // magic numbers abound - see previous comment
      $gate_crew_id = 2223;
      $slot_array = array();
      $i = 0;
      $slot_start = $seed_event_date;
      while ($i <= 56) {
        $slot_end = strtotime('8 hours', $slot_start);
        $slot_array[$i] = array (
          'id' => $i + 1,
          'begins' => date('Y-m-d H:i:s', $slot_start),
          'ends' => date('Y-m-d H:i:s', $slot_end),
          'position_id' => $gate_crew_id,
          'description' => 'Gate crew',
          'signed_up' => 0,
          'max' => 5,
          'min' => 1,
          'active' => 1,
          'url' => null,
          'trainer_slot_id' => null,
          'trainee_slot_id' => null,
        );
        $slot_start = $slot_end;
        $i += 1;
      }

      \DB::table('slot')->delete();
      \DB::table('slot')->insert($slot_array);
    }
}
