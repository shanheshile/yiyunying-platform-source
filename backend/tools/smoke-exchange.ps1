param(
    [string]$BaseUrl = 'http://127.0.0.1:8788'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null
    )
    $params = @{
        Method = $Method
        Uri = "$BaseUrl$Path"
        Headers = $Headers
        UseBasicParsing = $true
    }
    if ($null -ne $Body) {
        $params.ContentType = 'application/json; charset=utf-8'
        $params.Body = $Body | ConvertTo-Json -Depth 15 -Compress
    }
    $response = Invoke-RestMethod @params
    if ($response.code -ne 1) {
        throw "$Method $Path failed: $($response.msg)"
    }
    return $response.data
}

function Assert-ApiFailure {
    param(
        [string]$Method,
        [string]$Path,
        [hashtable]$Headers = @{},
        [object]$Body = $null,
        [int]$ExpectedCode
    )
    try {
        Invoke-Api -Method $Method -Path $Path -Headers $Headers -Body $Body | Out-Null
    } catch {
        $raw = $_.ErrorDetails.Message
        if ([string]::IsNullOrWhiteSpace($raw) -and $null -ne $_.Exception.Response) {
            $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
            $raw = $reader.ReadToEnd()
        }
        if (-not [string]::IsNullOrWhiteSpace($raw)) {
            $failure = $raw | ConvertFrom-Json
            if ([int]$failure.code -eq $ExpectedCode) {
                return $failure
            }
            throw "$Method $Path returned code $($failure.code), expected ${ExpectedCode}: $($failure.msg)"
        }
        throw
    }
    throw "$Method $Path unexpectedly succeeded"
}

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw "Assertion failed: $Message"
    }
    $script:Checks++
}

function Item-ByCode {
    param([object[]]$Items, [string]$Code)
    $matches = @($Items | Where-Object { $_.product_code -eq $Code })
    if ($matches.Count -ne 1) {
        throw "Expected exactly one product with code $Code"
    }
    return $matches[0]
}

$Checks = 0
$suffix = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$operatorAccount = "exchange_operator_$suffix"
$adminAccount = "exchange_admin_$suffix"
$userAccount = "exchange_user_$suffix"

$rootLogin = Invoke-Api POST '/api/platform/login' @{} @{
    account = 'root'; password = '123456'; device = 'exchange-smoke'
}
$rootHeaders = @{ Authorization = "Bearer $($rootLogin.access_token)" }

$operatorCreated = Invoke-Api POST '/api/platform/operators' $rootHeaders @{
    account = $operatorAccount
    password = '123456'
    nickname = 'Exchange Smoke Operator'
    membership_days = 30
    admin_quota = 10
}
$operatorId = [int]$operatorCreated.operator.id
$platformKey = [string]$operatorCreated.operator.platform_key

$operatorLogin = Invoke-Api POST '/api/platform/login' @{} @{
    account = $operatorAccount; password = '123456'; device = 'exchange-smoke'
}
$operatorHeaders = @{ Authorization = "Bearer $($operatorLogin.access_token)" }

$platformProducts = Invoke-Api GET '/api/platform/exchange-products' $operatorHeaders
Assert-True (@($platformProducts.items).Count -eq 4) 'new level 2 platform receives four default products'

$registration = Invoke-Api POST '/api/admin/register' @{} @{
    platform_key = $platformKey
    account = $adminAccount
    password = '123456'
    password_confirmation = '123456'
    nickname = 'Exchange Smoke Admin'
}
$adminId = [int]$registration.admin.id
Assert-True ([int]$registration.registration_gift.balance -eq 15) 'registration grants 15 platform balance'

$adminLogin = Invoke-Api POST '/api/admin/login' @{} @{
    platform_key = $platformKey; account = $adminAccount; password = '123456'; device = 'exchange-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($adminLogin.access_token)" }

$catalog = Invoke-Api GET '/api/admin/exchange-products' $adminHeaders
Assert-True ([int]$catalog.balance -eq 15) 'catalog exposes current platform balance'
$remoteProduct = Item-ByCode @($catalog.items) 'remote_document_1'
$vipProduct = Item-ByCode @($catalog.items) 'vip_day_1'
$appProduct = Item-ByCode @($catalog.items) 'app_quota_1'

$remoteQuote = Invoke-Api POST '/api/admin/exchanges/quote' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 2
}
Assert-True ([bool]$remoteQuote.quote.can_exchange) 'two remote document slots can be quoted'
Assert-True ([int]$remoteQuote.quote.total_balance -eq 10) 'remote document quote total is correct'
Assert-True ([int]$remoteQuote.quote.total_grant.remote_document_quota -eq 2) 'remote document grant is multiplied'

$remoteKey = "remote:$suffix"
$remoteExchange = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 2; idempotency_key = $remoteKey
}
$remoteOrderId = [int]$remoteExchange.order.id
Assert-True (-not [bool]$remoteExchange.idempotent) 'first exchange is not idempotent replay'
Assert-True ([int]$remoteExchange.entitlement.balance -eq 5) 'exchange atomically deducts balance'
Assert-True ([int]$remoteExchange.entitlement.remote_document_quota -eq 5) 'exchange atomically grants document quota'

$remoteReplay = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 2; idempotency_key = $remoteKey
}
Assert-True ([bool]$remoteReplay.idempotent) 'same idempotency key returns original result'
Assert-True ([int]$remoteReplay.order.id -eq $remoteOrderId) 'idempotent replay keeps the same order'
Assert-True ([int]$remoteReplay.entitlement.balance -eq 5) 'idempotent replay does not deduct twice'
Assert-ApiFailure POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 1; idempotency_key = $remoteKey
} 0 | Out-Null
$Checks++

$insufficientQuote = Invoke-Api POST '/api/admin/exchanges/quote' $adminHeaders @{
    product_id = [int]$appProduct.id; quantity = 1
}
Assert-True (-not [bool]$insufficientQuote.quote.can_exchange) 'insufficient integral is reported by quote'
Assert-True (@($insufficientQuote.quote.reasons) -contains 'balance_insufficient') 'quote gives balance_insufficient reason'

Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $operatorHeaders @{
    balance_change = 200; remark = 'Exchange smoke grant'
} | Out-Null
$entitlement = Invoke-Api GET '/api/admin/entitlement' $adminHeaders
Assert-True ([int]$entitlement.quotas.balance -eq 205) 'platform balance adjustment reaches admin ledger'

$appExchange = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$appProduct.id; quantity = 1; idempotency_key = "app:$suffix"
}
$appOrderId = [int]$appExchange.order.id
Assert-True ([int]$appExchange.entitlement.app_quota -eq 2) 'app quota exchange is delivered immediately'

$appOne = Invoke-Api POST '/api/admin/apps' $adminHeaders @{ name = "Exchange App One $suffix" }
$appTwo = Invoke-Api POST '/api/admin/apps' $adminHeaders @{ name = "Exchange App Two $suffix" }
$appOneId = [int]$appOne.app.id
$appTwoId = [int]$appTwo.app.id
$appKey = [string]$appOne.app.app_key
Assert-ApiFailure POST '/api/admin/apps' $adminHeaders @{ name = "Quota Overflow $suffix" } 0 | Out-Null
$Checks++

$userRegistration = Invoke-Api POST '/api/user/register' @{} @{
    app_key = $appKey; account = $userAccount; password = '123456'; password_confirmation = '123456'; nickname = 'Exchange User'
}
Assert-True (-not [string]::IsNullOrWhiteSpace($userRegistration.access_token)) 'downstream user works before expiry'

$past = (Get-Date).AddDays(-1).ToString('yyyy-MM-dd HH:mm:ss')
Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $operatorHeaders @{
    membership_status = 'active'; membership_expired_at = $past; remark = 'Expire for recovery test'
} | Out-Null
Assert-ApiFailure GET '/api/admin/apps' $adminHeaders $null 403 | Out-Null
$Checks++
Assert-ApiFailure POST '/api/user/login' @{} @{
    app_key = $appKey; account = $userAccount; password = '123456'
} 403 | Out-Null
$Checks++

$vipExchange = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$vipProduct.id; quantity = 1; idempotency_key = "vip:$suffix"
}
Assert-True ([string]$vipExchange.entitlement.membership_status -eq 'active') 'expired admin can exchange VIP in billing-only mode'
Assert-True (([DateTime]$vipExchange.entitlement.membership_expired_at) -gt (Get-Date)) 'VIP exchange extends expiry into the future'
Invoke-Api GET '/api/admin/apps' $adminHeaders | Out-Null
$userLogin = Invoke-Api POST '/api/user/login' @{} @{
    app_key = $appKey; account = $userAccount; password = '123456'
}
Assert-True (-not [string]::IsNullOrWhiteSpace($userLogin.access_token)) 'downstream user resumes immediately after VIP exchange'

Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $operatorHeaders @{
    membership_status = 'frozen'; remark = 'Freeze must not be self-recoverable'
} | Out-Null
$frozenLogin = Invoke-Api POST '/api/admin/login' @{} @{
    platform_key = $platformKey; account = $adminAccount; password = '123456'; device = 'exchange-frozen-smoke'
}
$adminHeaders = @{ Authorization = "Bearer $($frozenLogin.access_token)" }
$frozenQuote = Invoke-Api POST '/api/admin/exchanges/quote' $adminHeaders @{
    product_id = [int]$vipProduct.id; quantity = 1
}
Assert-True (@($frozenQuote.quote.reasons) -contains 'membership_frozen') 'platform freeze cannot be self-released by VIP exchange'
Assert-ApiFailure POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$vipProduct.id; quantity = 1; idempotency_key = "frozen:$suffix"
} 0 | Out-Null
$Checks++
Invoke-Api PUT "/api/platform/admins/$adminId/entitlement" $operatorHeaders @{
    membership_status = 'active'; remark = 'Release exchange smoke freeze'
} | Out-Null

Assert-ApiFailure POST "/api/platform/exchanges/$appOrderId/refund" $operatorHeaders @{
    refund_reason = 'Must fail while exchanged app slot is occupied'
} 0 | Out-Null
$Checks++
Invoke-Api DELETE "/api/admin/apps/$appTwoId" $adminHeaders @{ confirm = 'DELETE' } | Out-Null
$appRefund = Invoke-Api POST "/api/platform/exchanges/$appOrderId/refund" $operatorHeaders @{
    refund_reason = 'Released exchanged app slot'
}
Assert-True ([string]$appRefund.order.status -eq 'refunded') 'app quota order refunds after resource release'
Assert-True ([int]$appRefund.entitlement.app_quota -eq 1) 'refund reverses app quota'
Assert-ApiFailure POST "/api/platform/exchanges/$appOrderId/refund" $operatorHeaders @{
    refund_reason = 'Duplicate refund'
} 0 | Out-Null
$Checks++

for ($i = 1; $i -le 5; $i++) {
    Invoke-Api POST "/api/admin/apps/$appOneId/remote-files" $adminHeaders @{
        name = "exchange-file-$i.txt"; content = "file-$i"; file_type = 'file'; visibility = 'private'
    } | Out-Null
}
Assert-ApiFailure POST "/api/admin/apps/$appOneId/remote-files" $adminHeaders @{
    name = 'quota-overflow.txt'; content = 'overflow'; file_type = 'file'; visibility = 'private'
} 0 | Out-Null
$Checks++
Assert-ApiFailure POST "/api/platform/exchanges/$remoteOrderId/refund" $operatorHeaders @{
    refund_reason = 'Must fail while document slots are occupied'
} 0 | Out-Null
$Checks++

Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{
    settings = @{ balance_exchange_enabled = $false }
} | Out-Null
$disabledQuote = Invoke-Api POST '/api/admin/exchanges/quote' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 1
}
Assert-True (-not [bool]$disabledQuote.quote.can_exchange) 'platform master switch disables quote execution'
Assert-True (@($disabledQuote.quote.reasons) -contains 'platform_exchange_disabled') 'master switch reason is explicit'
Assert-ApiFailure POST '/api/admin/exchanges' $adminHeaders @{
    product_id = [int]$remoteProduct.id; quantity = 1; idempotency_key = "disabled:$suffix"
} 0 | Out-Null
$Checks++
Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{
    settings = @{ balance_exchange_enabled = $true }
} | Out-Null

$limitedProduct = Invoke-Api POST '/api/platform/exchange-products' $operatorHeaders @{
    product_code = "limited_$suffix"
    name = 'Limited document slot'
    product_type = 'remote_document_quota'
    grant = @{ remote_document_quota = 1 }
    price_balance = 1
    stock = 1
    per_admin_limit = 1
    per_admin_daily_limit = 1
}
$limitedId = [int]$limitedProduct.product.id
$limitedExchange = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = $limitedId; quantity = 1; idempotency_key = "limited-one:$suffix"
}
$limitedOrderId = [int]$limitedExchange.order.id
Assert-ApiFailure POST '/api/admin/exchanges' $adminHeaders @{
    product_id = $limitedId; quantity = 1; idempotency_key = "limited-two:$suffix"
} 0 | Out-Null
$Checks++
$limitedRefund = Invoke-Api POST "/api/platform/exchanges/$limitedOrderId/refund" $operatorHeaders @{
    refund_reason = 'Restore stock and limits'
}
Assert-True ([string]$limitedRefund.order.status -eq 'refunded') 'limited product can refund unused quota'
$limitedAgain = Invoke-Api POST '/api/admin/exchanges' $adminHeaders @{
    product_id = $limitedId; quantity = 1; idempotency_key = "limited-three:$suffix"
}
Assert-True (-not [bool]$limitedAgain.idempotent) 'refund releases stock and limit for a new exchange'
Invoke-Api POST "/api/platform/exchanges/$([int]$limitedAgain.order.id)/refund" $operatorHeaders @{
    refund_reason = 'Final limited product cleanup'
} | Out-Null

Invoke-Api POST "/api/platform/exchange-products/$limitedId/disable" $operatorHeaders | Out-Null
Assert-ApiFailure POST '/api/admin/exchanges' $adminHeaders @{
    product_id = $limitedId; quantity = 1; idempotency_key = "product-disabled:$suffix"
} 404 | Out-Null
$Checks++
Invoke-Api POST "/api/platform/exchange-products/$limitedId/enable" $operatorHeaders | Out-Null

Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{
    settings = @{ balance_exchange_admin_daily_limit = 1 }
} | Out-Null
$dailyLimitQuote = Invoke-Api POST '/api/admin/exchanges/quote' $adminHeaders @{
    product_id = $limitedId; quantity = 1
}
Assert-True (@($dailyLimitQuote.quote.reasons) -contains 'daily_balance_limit_exceeded') 'platform daily balance limit is enforced'
Invoke-Api PUT '/api/platform/settings' $operatorHeaders @{
    settings = @{ balance_exchange_admin_daily_limit = 0 }
} | Out-Null

$balanceLogs = Invoke-Api GET '/api/admin/balance-logs?limit=100' $adminHeaders
Assert-True (@($balanceLogs.items | Where-Object { $_.scene -eq 'registration_gift' }).Count -eq 1) 'registration gift has a balance ledger entry'
Assert-True (@($balanceLogs.items | Where-Object { $_.scene -eq 'platform_adjustment' }).Count -ge 1) 'platform grant has a balance ledger entry'
Assert-True (@($balanceLogs.items | Where-Object { $_.scene -eq 'point_exchange' }).Count -ge 3) 'exchanges have debit ledger entries'
Assert-True (@($balanceLogs.items | Where-Object { $_.scene -eq 'point_exchange_refund' }).Count -ge 2) 'refunds have credit ledger entries'

$operatorOrders = Invoke-Api GET '/api/platform/exchanges?limit=100' $operatorHeaders
Assert-True (@($operatorOrders.items).Count -ge 5) 'level 2 can audit its exchange orders'
$rootOrders = Invoke-Api GET "/api/platform/exchanges?platform_id=$operatorId&limit=100" $rootHeaders
Assert-True (@($rootOrders.items).Count -eq @($operatorOrders.items).Count) 'level 1 can audit the selected level 2 exchange branch'
$dashboard = Invoke-Api GET '/api/platform/dashboard' $operatorHeaders
Assert-True ([int]$dashboard.finance.balance_exchange_orders -ge 2) 'dashboard includes net completed exchange orders'
Assert-True ([int]$dashboard.finance.balance_exchange_amount -gt 0) 'dashboard includes net exchange balance'

Invoke-Api DELETE "/api/platform/operators/$operatorId" $rootHeaders @{ confirm = 'DELETE' } | Out-Null

Write-Host 'Yiyunying automatic balance-exchange maximum-loop smoke test passed.'
Write-Host "checks=$Checks"
Write-Host 'Validated: catalog, quote, balance, atomic delivery, idempotency, insufficient balance, app/document/VIP benefits, expiry recovery, freeze protection, resource-aware refund, stock, limits, master switch, ledger and L1/L2 scope.'
