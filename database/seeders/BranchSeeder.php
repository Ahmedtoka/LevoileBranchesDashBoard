<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            ['branch_name' => 'Heliopolis - El Marghany', 'area' => 'Heliopolis', 'city' => 'Cairo', 'address' => '126 El-Marghany St., Next to Shawermer', 'google_maps_url' => 'https://goo.gl/maps/EbSV5rzAqCvvyAD37', 'mobile' => '01094538159'],
            ['branch_name' => 'Heliopolis - El Hegaz', 'area' => 'Heliopolis', 'city' => 'Cairo', 'address' => '7 Ali Abd El-Razek St., Parallel to Ammar Ibn Yasser St', 'google_maps_url' => 'https://goo.gl/maps/RFo6gESFKDgjSN8x5', 'mobile' => '01063498056'],
            ['branch_name' => 'Nasr City - Abbas El Akkad / Ezzat Salama', 'area' => 'Nasr City', 'city' => 'Cairo', 'address' => '35 Ezzat Salama St., End of Hussein Heikal St., Abbas El-Akkad', 'google_maps_url' => 'https://goo.gl/maps/uRQpbNA7naTHhCTL8', 'mobile' => '01094170690'],
            ['branch_name' => 'Nasr City - Nozha Street', 'area' => 'Nasr City', 'city' => 'Cairo', 'address' => '4 El Nozha St., Infront of Mobil benzene', 'google_maps_url' => 'https://goo.gl/maps/ke5Vm5KVU3EdYNZL8', 'mobile' => '01033741083'],
            ['branch_name' => 'Nasr City - Abbas El Akkad', 'area' => 'Nasr City', 'city' => 'Cairo', 'address' => '13 Abbas El-Akkad St.', 'google_maps_url' => 'https://goo.gl/maps/7jYr5nZrJrPJ2nMP9', 'mobile' => '01033184102'],
            ['branch_name' => 'Nasr City - Gnena Mall', 'area' => 'Nasr City', 'city' => 'Cairo', 'address' => 'Abbas el-Akkad St, Nasr City, Cairo Governorate 11432', 'google_maps_url' => 'https://maps.app.goo.gl/WrN3iHDgPuXSzbhe8', 'mobile' => '01070989182'],
            ['branch_name' => 'Nasr City - City Stars Mall', 'area' => 'Nasr City', 'city' => 'Cairo', 'address' => 'Masaken Al Mohandesin, Nasr City, Cairo Governorate 4451620', 'google_maps_url' => 'https://maps.app.goo.gl/ye6Zpbeyfg3tiww49', 'mobile' => '01070998545'],
            ['branch_name' => '5th Settlement - Galleria Mall', 'area' => '5th Settlement', 'city' => 'New Cairo', 'address' => '90 St., Tagamoa Al-Khames, Galleria Mall, Beside Dunkin Donuts', 'google_maps_url' => 'https://goo.gl/maps/hbdMCHMB5KHYUEm47', 'mobile' => '01050092640'],
            ['branch_name' => '5th Settlement - Point 90 Mall', 'area' => '5th Settlement', 'city' => 'New Cairo', 'address' => 'Point 90 Mall, 1st floor, Booth F-K-27, Infront of H&M', 'google_maps_url' => 'https://goo.gl/maps/tLnvK5x94vECEjx28', 'mobile' => '01050092670'],
            ['branch_name' => '5th Settlement - El Rebat Mall', 'area' => '5th Settlement', 'city' => 'New Cairo', 'address' => 'El Rebat Mall, North 90, 5th Settlement, New Cairo', 'google_maps_url' => 'https://maps.app.goo.gl/eUkmVDgkhRYwE3GXA', 'mobile' => '01040026013'],
            ['branch_name' => 'El Rehab - The Yard Mall', 'area' => 'El Rehab', 'city' => 'New Cairo', 'address' => 'Next to Gate 6, First floor, Clinic 116, The Yard, Elite Medical Park', 'google_maps_url' => 'https://maps.app.goo.gl/sH5PRan3jnPRR3Y29', 'mobile' => '01070989214'],
            ['branch_name' => 'Madinaty - Open Air Mall', 'area' => 'Madinaty', 'city' => 'New Cairo', 'address' => 'Open Air Mall A, Second New Cairo, Cairo Governorate 4770203', 'google_maps_url' => 'https://goo.gl/maps/hKZtKUxdJzK8PkRL8', 'mobile' => '01030221108'],
            ['branch_name' => 'El Mokattam - Mokattam 1', 'area' => 'El Mokattam', 'city' => 'Cairo', 'address' => '11571 Street 9, Al Abageyah, El Khalifa, Cairo Governorate', 'google_maps_url' => 'https://goo.gl/maps/8THdBS9xTGkZZeLx5', 'mobile' => '01023959862'],
            ['branch_name' => 'El Mokattam - Mokattam 2', 'area' => 'El Mokattam', 'city' => 'Cairo', 'address' => '28 Street 9, Al Abageyah, El Khalifa, Cairo Governorate', 'google_maps_url' => 'https://www.google.com/maps?q=30.0140549,31.2855332&z=17&hl=en', 'mobile' => '01013425577', 'latitude' => 30.0140549, 'longitude' => 31.2855332],
            ['branch_name' => 'Maadi - Maadi 1', 'area' => 'Maadi', 'city' => 'Cairo', 'address' => '11 Laselky St., Next to Club Aldo', 'google_maps_url' => 'https://goo.gl/maps/kt1S4CDRBaMPbdXXA', 'mobile' => '01060915685'],
            ['branch_name' => 'Maadi - Maadi 2', 'area' => 'Maadi', 'city' => 'Cairo', 'address' => '4D, 4 El Nasr Street, New Maadi, Below Starbucks, Next to LG', 'google_maps_url' => 'https://maps.app.goo.gl/tRmMQLDYP4yZReuW8', 'mobile' => '01066619656'],
            ['branch_name' => 'El Mohandessin - Mohandessin Store', 'area' => 'El Mohandessin', 'city' => 'Giza', 'address' => '41 Shehab St., Agouza, Giza Governorate', 'google_maps_url' => 'https://goo.gl/maps/L5k7bgbxzCaWZhia8', 'mobile' => '01063716436'],
            ['branch_name' => '6th of October - Mall of Egypt Store', 'area' => '6th of October', 'city' => 'Giza', 'address' => 'El Wahat Rd, First 6th of October, Next to H&M, Second Floor', 'google_maps_url' => 'https://goo.gl/maps/e1pVwnAyiB9ZFgho6', 'mobile' => '01033852064'],
            ['branch_name' => '6th of October - Mall of Arabia', 'area' => '6th of October', 'city' => 'Giza', 'address' => 'In front of LG, Mall Of Arabia Gate 23, Beside Virgin Megastore', 'google_maps_url' => 'https://goo.gl/maps/AXuaQ33epVyMct3q7', 'mobile' => '01030211223'],
            ['branch_name' => 'Sheikh Zayed - Saraya Mall', 'area' => 'Sheikh Zayed', 'city' => 'Giza', 'address' => 'Saraya Mall 1st floor, Next to Seoudi Market, Sheikh Zayed City', 'google_maps_url' => 'https://goo.gl/maps/xUbXFNGw8YTQpk9s5', 'mobile' => '01033298717'],
            ['branch_name' => 'Alexandria - El Ekbal', 'area' => 'San Stefano', 'city' => 'Alexandria', 'address' => '4 Al Ekbal St., In-front of Al Ekbal School, San Stefano, Qesm AR Ramel', 'google_maps_url' => 'https://goo.gl/maps/LyADBFggYLRnpVn8A', 'mobile' => '01099116756'],
            ['branch_name' => 'Alexandria - Smouha', 'area' => 'Smouha', 'city' => 'Alexandria', 'address' => '26 Mostafa Kamel St., Smouha, Sidi Gaber', 'google_maps_url' => 'https://goo.gl/maps/vi8zGPdKpDm49DJH9', 'mobile' => '01050338942'],
            ['branch_name' => 'Alexandria - Miami', 'area' => 'Miami', 'city' => 'Alexandria', 'address' => '276 Gamal Abd El-naser St., Miami', 'google_maps_url' => 'https://goo.gl/maps/n9LLaMSExXoTjWDQ7', 'mobile' => '01050338947'],
            ['branch_name' => 'Alexandria - San Stefano Mall', 'area' => 'San Stefano', 'city' => 'Alexandria', 'address' => 'San Stefano Mall, Unit G 44, Ground Floor', 'google_maps_url' => 'https://maps.app.goo.gl/78LQoJFBdPc1hPns6', 'mobile' => '01067725537'],
            ['branch_name' => 'Mansoura - Mansoura Store', 'area' => 'Mansoura', 'city' => 'Dakahlia', 'address' => 'Al Mashaya, Al Sofleya St., El Mansoura', 'google_maps_url' => 'https://goo.gl/maps/6fE297hqST5SL4qL9', 'mobile' => '01023079073'],
            ['branch_name' => 'Zagazig - Zagazig Store 1', 'area' => 'Zagazig', 'city' => 'Sharkeya', 'address' => '2 Ibn Khaldoun St., Off Tolba Oweida St., Al Salam District II', 'google_maps_url' => 'https://goo.gl/maps/TzxaCEBhjidSTKxSA', 'mobile' => '01030227355'],
        ];

        foreach ($branches as $branch) {
            Branch::updateOrCreate(
                ['branch_name' => $branch['branch_name']],
                array_merge(['active' => true, 'checkin_radius' => 150], $branch)
            );
        }
    }
}
