<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A purok (zone) of a barangay, cached from GET /puroks, with its high-risk marks. */
class Purok extends Model
{
    protected $fillable = ['remote_id', 'barangay_remote_id', 'name', 'high_risk_flood', 'high_risk_landslide'];

    protected $casts = ['high_risk_flood' => 'boolean', 'high_risk_landslide' => 'boolean'];

    public function isHighRisk(): bool
    {
        return $this->high_risk_flood || $this->high_risk_landslide;
    }

    /** "Flood", "Landslide", "Flood and landslide", or null. */
    public function riskLabel(): ?string
    {
        return match (true) {
            $this->high_risk_flood && $this->high_risk_landslide => 'Flood and landslide',
            $this->high_risk_flood => 'Flood',
            $this->high_risk_landslide => 'Landslide',
            default => null,
        };
    }

    /** The cached columns from one row of GET /puroks. */
    public static function attributesFromServer(array $row): array
    {
        return [
            'barangay_remote_id' => $row['barangay_id'],
            'name' => $row['name'],
            'high_risk_flood' => (bool) ($row['high_risk_flood'] ?? false),
            'high_risk_landslide' => (bool) ($row['high_risk_landslide'] ?? false),
        ];
    }
}
