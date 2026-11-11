<?php

namespace App\Models;

use App\Enums\PartnerInquiryType;
use Database\Factories\PartnerInquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One partner inquiry from the website form (M6-S03). Written only by
 * PartnerInquiryController; read-only in /admin (superadmin); deleted after
 * RETENTION_MONTHS by `partner-inquiries:prune`.
 *
 * @property int $id
 * @property string $company
 * @property string $contact_name
 * @property string $email
 * @property PartnerInquiryType $type
 * @property string $message
 * @property string|null $locale
 * @property Carbon $created_at
 */
class PartnerInquiry extends Model
{
    /** @use HasFactory<PartnerInquiryFactory> */
    use HasFactory;

    /** WEBSITE_SALES_SPEC §5.2: inquiries are deleted after 12 months. */
    public const RETENTION_MONTHS = 12;

    /**
     * @var list<string>
     */
    protected $fillable = ['company', 'contact_name', 'email', 'type', 'message', 'locale'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => PartnerInquiryType::class];
    }
}
