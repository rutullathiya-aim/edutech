<?php

namespace App\Enums;

enum IndianState: string
{
    case AndhraPradesh = 'Andhra Pradesh';
    case ArunachalPradesh = 'Arunachal Pradesh';
    case Assam = 'Assam';
    case Bihar = 'Bihar';
    case Chhattisgarh = 'Chhattisgarh';
    case Goa = 'Goa';
    case Gujarat = 'Gujarat';
    case Haryana = 'Haryana';
    case HimachalPradesh = 'Himachal Pradesh';
    case Jharkhand = 'Jharkhand';
    case Karnataka = 'Karnataka';
    case Kerala = 'Kerala';
    case MadhyaPradesh = 'Madhya Pradesh';
    case Maharashtra = 'Maharashtra';
    case Manipur = 'Manipur';
    case Meghalaya = 'Meghalaya';
    case Mizoram = 'Mizoram';
    case Nagaland = 'Nagaland';
    case Odisha = 'Odisha';
    case Punjab = 'Punjab';
    case Rajasthan = 'Rajasthan';
    case Sikkim = 'Sikkim';
    case TamilNadu = 'Tamil Nadu';
    case Telangana = 'Telangana';
    case Tripura = 'Tripura';
    case UttarPradesh = 'Uttar Pradesh';
    case Uttarakhand = 'Uttarakhand';
    case WestBengal = 'West Bengal';
    case AndamanAndNicobarIslands = 'Andaman and Nicobar Islands';
    case Chandigarh = 'Chandigarh';
    case DadraAndNagarHaveliAndDamanAndDiu = 'Dadra and Nagar Haveli and Daman and Diu';
    case Delhi = 'Delhi';
    case JammuAndKashmir = 'Jammu and Kashmir';
    case Ladakh = 'Ladakh';
    case Lakshadweep = 'Lakshadweep';
    case Puducherry = 'Puducherry';

    /**
     * Get all state and UT names as an array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
