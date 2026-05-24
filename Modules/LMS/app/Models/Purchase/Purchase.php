<?php

namespace Modules\LMS\Models\Purchase;

use Modules\LMS\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\LMS\Models\PaymentDocument;

class Purchase extends Model
{
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     */
    protected $guarded = ['id'];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentDocument(): HasOne
    {
        return $this->hasOne(PaymentDocument::class);
    }


    public function purchaseDetails()
    {
        return $this->hasMany(PurchaseDetails::class);
    }
}
