<?php

namespace Modules\Accounting\database\seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Entities\Account;
use Modules\Accounting\Enums\AccountType;
use Modules\Accounting\Enums\NormalBalance;

class DefaultChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->accounts() as $sortOrder => $data) {
                $parent = $data['parent'] ? Account::query()->where('code', $data['parent'])->first() : null;
                $existing = Account::query()->where('code', $data['code'])->first();

                // اعادة التشغيل لا تكسر قواعد الشجرة: الحساب الذي صار له ابناء يبقى مجموعة،
                // والحساب الطرفي الذي عليه قيود يبقى طرفياً.
                if ($existing?->children()->exists()) {
                    $data['postable'] = false;
                } elseif ($existing?->hasJournalLines()) {
                    $data['postable'] = $existing->is_postable;
                }

                $account = Account::updateOrCreate(
                    ['code' => $data['code']],
                    [
                        'parent_id' => $parent?->id,
                        'type' => $data['type']->value(),
                        'normal_balance' => ($data['normal_balance'] ?? $this->normalBalance($data['type']))->value(),
                        'sort_order' => ($sortOrder + 1) * 10,
                        'is_postable' => $data['postable'],
                        'is_active' => true,
                        'created_by' => null,
                    ]
                );

                $account->translateOrNew('en')->fill([
                    'name' => $data['en'],
                    'description' => $data['description_en'] ?? null,
                    'created_by' => null,
                ]);

                $account->translateOrNew('ar')->fill([
                    'name' => $data['ar'],
                    'description' => $data['description_ar'] ?? null,
                    'created_by' => null,
                ]);

                $account->save();
                $account->refreshHierarchy();
            }
        });
    }

    private function accounts(): array
    {
        return [
            // Assets
            ['code' => '1000', 'parent' => null, 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Assets', 'ar' => 'الأصول'],
            ['code' => '1100', 'parent' => '1000', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Current Assets', 'ar' => 'الأصول المتداولة'],
            ['code' => '1110', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Cash on Hand', 'ar' => 'النقدية في الصندوق'],
            ['code' => '1115', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Petty Cash', 'ar' => 'العهدة النقدية'],
            ['code' => '1120', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Bank Account', 'ar' => 'الحساب البنكي'],
            ['code' => '1125', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Cheques Under Collection', 'ar' => 'شيكات تحت التحصيل'],
            ['code' => '1130', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Accounts Receivable', 'ar' => 'الذمم المدينة'],
            ['code' => '1135', 'parent' => '1100', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Allowance for Doubtful Accounts', 'ar' => 'مخصص الديون المشكوك في تحصيلها'],
            ['code' => '1140', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Inventory', 'ar' => 'المخزون'],
            ['code' => '1150', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Prepaid Expenses', 'ar' => 'المصروفات المدفوعة مقدما'],
            ['code' => '1151', 'parent' => '1150', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Prepaid Insurance', 'ar' => 'تأمين مدفوع مقدما'],
            ['code' => '1152', 'parent' => '1150', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Prepaid Rent', 'ar' => 'إيجار مدفوع مقدما'],
            ['code' => '1160', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Employee Advances', 'ar' => 'سلف الموظفين'],
            ['code' => '1170', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Supplier Advances', 'ar' => 'دفعات مقدمة للموردين'],
            ['code' => '1180', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'VAT Receivable', 'ar' => 'ضريبة قيمة مضافة قابلة للاسترداد'],
            ['code' => '1190', 'parent' => '1100', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Other Current Assets', 'ar' => 'أصول متداولة أخرى'],

            ['code' => '1200', 'parent' => '1000', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Non-current Assets', 'ar' => 'الأصول غير المتداولة'],
            ['code' => '1210', 'parent' => '1200', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Property, Plant and Equipment', 'ar' => 'الممتلكات والآلات والمعدات'],
            ['code' => '1211', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Land', 'ar' => 'الأراضي'],
            ['code' => '1212', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Buildings', 'ar' => 'المباني'],
            ['code' => '1213', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Machinery and Equipment', 'ar' => 'الآلات والمعدات'],
            ['code' => '1214', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Vehicles', 'ar' => 'المركبات'],
            ['code' => '1215', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Furniture and Fixtures', 'ar' => 'الأثاث والتجهيزات'],
            ['code' => '1216', 'parent' => '1210', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Computers and IT Equipment', 'ar' => 'الحواسيب ومعدات تقنية المعلومات'],
            ['code' => '1220', 'parent' => '1200', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => false, 'en' => 'Accumulated Depreciation', 'ar' => 'مجمع الإهلاك'],
            ['code' => '1221', 'parent' => '1220', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Accumulated Depreciation - Buildings', 'ar' => 'مجمع إهلاك المباني'],
            ['code' => '1222', 'parent' => '1220', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Accumulated Depreciation - Equipment', 'ar' => 'مجمع إهلاك المعدات'],
            ['code' => '1223', 'parent' => '1220', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Accumulated Depreciation - Vehicles', 'ar' => 'مجمع إهلاك المركبات'],
            ['code' => '1224', 'parent' => '1220', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Accumulated Depreciation - Furniture', 'ar' => 'مجمع إهلاك الأثاث'],
            ['code' => '1225', 'parent' => '1220', 'type' => AccountType::ASSET, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Accumulated Depreciation - Computers', 'ar' => 'مجمع إهلاك الحواسيب'],
            ['code' => '1230', 'parent' => '1200', 'type' => AccountType::ASSET, 'postable' => false, 'en' => 'Intangible Assets', 'ar' => 'الأصول غير الملموسة'],
            ['code' => '1231', 'parent' => '1230', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Software', 'ar' => 'البرمجيات'],
            ['code' => '1232', 'parent' => '1230', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Trademarks', 'ar' => 'العلامات التجارية'],
            ['code' => '1240', 'parent' => '1200', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Long-term Investments', 'ar' => 'استثمارات طويلة الأجل'],
            ['code' => '1250', 'parent' => '1200', 'type' => AccountType::ASSET, 'postable' => true, 'en' => 'Security Deposits', 'ar' => 'تأمينات مستردة'],

            // Liabilities
            ['code' => '2000', 'parent' => null, 'type' => AccountType::LIABILITY, 'postable' => false, 'en' => 'Liabilities', 'ar' => 'الالتزامات'],
            ['code' => '2100', 'parent' => '2000', 'type' => AccountType::LIABILITY, 'postable' => false, 'en' => 'Current Liabilities', 'ar' => 'الالتزامات المتداولة'],
            ['code' => '2110', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => false, 'en' => 'Accounts Payable', 'ar' => 'الذمم الدائنة'],
            ['code' => '2115', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Cheques Payable', 'ar' => 'شيكات دفع'],
            ['code' => '2120', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Accrued Expenses', 'ar' => 'مصروفات مستحقة'],
            ['code' => '2130', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'VAT Payable', 'ar' => 'ضريبة قيمة مضافة مستحقة'],
            ['code' => '2140', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Sales Tax Payable', 'ar' => 'ضريبة مبيعات مستحقة'],
            ['code' => '2150', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => false, 'en' => 'Payroll Payable', 'ar' => 'مستحقات الرواتب'],
            ['code' => '2151', 'parent' => '2150', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Salaries Payable', 'ar' => 'رواتب مستحقة'],
            ['code' => '2152', 'parent' => '2150', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Social Security Payable', 'ar' => 'ضمان اجتماعي مستحق'],
            ['code' => '2153', 'parent' => '2150', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Payroll Tax Payable', 'ar' => 'ضريبة رواتب مستحقة'],
            ['code' => '2160', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Short-term Loans', 'ar' => 'قروض قصيرة الأجل'],
            ['code' => '2170', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Current Portion of Long-term Debt', 'ar' => 'الجزء المتداول من القروض طويلة الأجل'],
            ['code' => '2180', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Customer Deposits', 'ar' => 'تأمينات العملاء'],
            ['code' => '2190', 'parent' => '2100', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Unearned Revenue', 'ar' => 'إيرادات غير مكتسبة'],
            ['code' => '2200', 'parent' => '2000', 'type' => AccountType::LIABILITY, 'postable' => false, 'en' => 'Non-current Liabilities', 'ar' => 'الالتزامات غير المتداولة'],
            ['code' => '2210', 'parent' => '2200', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Long-term Loans', 'ar' => 'قروض طويلة الأجل'],
            ['code' => '2220', 'parent' => '2200', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Lease Liabilities', 'ar' => 'التزامات عقود الإيجار'],
            ['code' => '2230', 'parent' => '2200', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Deferred Tax Liabilities', 'ar' => 'التزامات ضريبية مؤجلة'],
            ['code' => '2240', 'parent' => '2200', 'type' => AccountType::LIABILITY, 'postable' => true, 'en' => 'Other Non-current Liabilities', 'ar' => 'التزامات غير متداولة أخرى'],

            // Equity
            ['code' => '3000', 'parent' => null, 'type' => AccountType::EQUITY, 'postable' => false, 'en' => 'Equity', 'ar' => 'حقوق الملكية'],
            ['code' => '3100', 'parent' => '3000', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Owner Capital', 'ar' => 'رأس المال'],
            ['code' => '3200', 'parent' => '3000', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Retained Earnings', 'ar' => 'الأرباح المحتجزة'],
            ['code' => '3300', 'parent' => '3000', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Current Year Earnings', 'ar' => 'أرباح السنة الحالية'],
            ['code' => '3400', 'parent' => '3000', 'type' => AccountType::EQUITY, 'normal_balance' => NormalBalance::DEBIT, 'postable' => true, 'en' => 'Owner Drawings / Dividends', 'ar' => 'مسحوبات المالك / توزيعات الأرباح'],
            ['code' => '3500', 'parent' => '3000', 'type' => AccountType::EQUITY, 'postable' => false, 'en' => 'Reserves', 'ar' => 'الاحتياطيات'],
            ['code' => '3510', 'parent' => '3500', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Legal Reserve', 'ar' => 'الاحتياطي القانوني'],
            ['code' => '3520', 'parent' => '3500', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Revaluation Reserve', 'ar' => 'احتياطي إعادة التقييم'],
            ['code' => '3600', 'parent' => '3000', 'type' => AccountType::EQUITY, 'postable' => true, 'en' => 'Other Comprehensive Income', 'ar' => 'الدخل الشامل الآخر'],

            // Revenue
            ['code' => '4000', 'parent' => null, 'type' => AccountType::REVENUE, 'postable' => false, 'en' => 'Revenue', 'ar' => 'الإيرادات'],
            ['code' => '4100', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Sales Revenue', 'ar' => 'إيرادات المبيعات'],
            ['code' => '4110', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Service Revenue', 'ar' => 'إيرادات الخدمات'],
            ['code' => '4120', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Export Sales', 'ar' => 'مبيعات التصدير'],
            ['code' => '4130', 'parent' => '4000', 'type' => AccountType::REVENUE, 'normal_balance' => NormalBalance::DEBIT, 'postable' => true, 'en' => 'Sales Returns and Allowances', 'ar' => 'مردودات ومسموحات المبيعات'],
            ['code' => '4140', 'parent' => '4000', 'type' => AccountType::REVENUE, 'normal_balance' => NormalBalance::DEBIT, 'postable' => true, 'en' => 'Sales Discounts', 'ar' => 'خصومات المبيعات'],
            ['code' => '4150', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Transport Service Revenue', 'ar' => 'إيرادات خدمات النقل'],
            ['code' => '4200', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Other Operating Revenue', 'ar' => 'إيرادات تشغيلية أخرى'],
            ['code' => '4300', 'parent' => '4000', 'type' => AccountType::REVENUE, 'postable' => false, 'en' => 'Other Income', 'ar' => 'إيرادات أخرى'],
            ['code' => '4310', 'parent' => '4300', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Interest Income', 'ar' => 'إيرادات فوائد'],
            ['code' => '4320', 'parent' => '4300', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Gain on Disposal of Assets', 'ar' => 'أرباح بيع الأصول'],
            ['code' => '4330', 'parent' => '4300', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Foreign Exchange Gain', 'ar' => 'أرباح فروقات العملة'],
            ['code' => '4340', 'parent' => '4300', 'type' => AccountType::REVENUE, 'postable' => true, 'en' => 'Miscellaneous Income', 'ar' => 'إيرادات متنوعة'],

            // Expenses and cost of sales
            ['code' => '5000', 'parent' => null, 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Expenses', 'ar' => 'المصروفات'],
            ['code' => '5100', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Cost of Goods Sold', 'ar' => 'تكلفة البضاعة المباعة'],
            ['code' => '5110', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Purchases', 'ar' => 'المشتريات'],
            ['code' => '5120', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Freight In', 'ar' => 'مصاريف شحن المشتريات'],
            ['code' => '5130', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Purchase Returns and Allowances', 'ar' => 'مردودات ومسموحات المشتريات'],
            ['code' => '5140', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Inventory Shrinkage', 'ar' => 'عجز المخزون'],
            ['code' => '5150', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Direct Labor', 'ar' => 'العمالة المباشرة'],
            ['code' => '5160', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Cost of Services Rendered', 'ar' => 'تكلفة الخدمات المقدمة'],
            ['code' => '5170', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'normal_balance' => NormalBalance::CREDIT, 'postable' => true, 'en' => 'Applied Internal Service Costs', 'ar' => 'تكاليف خدمات داخلية محمّلة'],

            ['code' => '5200', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Operating Expenses', 'ar' => 'مصروفات تشغيلية'],
            ['code' => '5210', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Salaries and Wages Expense', 'ar' => 'مصروف الرواتب والأجور'],
            ['code' => '5220', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Rent Expense', 'ar' => 'مصروف الإيجار'],
            ['code' => '5230', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Utilities Expense', 'ar' => 'مصروف المنافع'],
            ['code' => '5240', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Insurance Expense', 'ar' => 'مصروف التأمين'],
            ['code' => '5250', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Depreciation Expense', 'ar' => 'مصروف الإهلاك'],
            ['code' => '5260', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Amortization Expense', 'ar' => 'مصروف الإطفاء'],
            ['code' => '5270', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Office Supplies Expense', 'ar' => 'مصروف القرطاسية والمستلزمات المكتبية'],
            ['code' => '5280', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Repairs and Maintenance', 'ar' => 'الصيانة والإصلاحات'],
            ['code' => '5290', 'parent' => '5200', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Marketing and Advertising', 'ar' => 'التسويق والإعلان'],

            ['code' => '5300', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Selling and Distribution Expenses', 'ar' => 'مصروفات البيع والتوزيع'],
            ['code' => '5310', 'parent' => '5300', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Sales Commissions', 'ar' => 'عمولات المبيعات'],
            ['code' => '5320', 'parent' => '5300', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Delivery and Freight Out', 'ar' => 'مصروف التوصيل والشحن للخارج'],
            ['code' => '5330', 'parent' => '5300', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Packaging Expense', 'ar' => 'مصروف التغليف'],

            ['code' => '5400', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Administrative Expenses', 'ar' => 'مصروفات إدارية'],
            ['code' => '5410', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Professional Fees', 'ar' => 'أتعاب مهنية'],
            ['code' => '5420', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Legal Fees', 'ar' => 'أتعاب قانونية'],
            ['code' => '5430', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Bank Charges', 'ar' => 'عمولات ومصاريف بنكية'],
            ['code' => '5440', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Software Subscriptions', 'ar' => 'اشتراكات البرمجيات'],
            ['code' => '5450', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Travel and Entertainment', 'ar' => 'السفر والضيافة'],
            ['code' => '5460', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Training Expense', 'ar' => 'مصروف التدريب'],
            ['code' => '5470', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Communication Expense', 'ar' => 'مصروف الاتصالات'],
            ['code' => '5480', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Taxes and Licenses', 'ar' => 'الضرائب والرخص'],
            ['code' => '5490', 'parent' => '5400', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Bad Debt Expense', 'ar' => 'مصروف الديون المعدومة'],

            ['code' => '5500', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Finance Expenses', 'ar' => 'مصروفات تمويلية'],
            ['code' => '5510', 'parent' => '5500', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Interest Expense', 'ar' => 'مصروف فوائد'],
            ['code' => '5520', 'parent' => '5500', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Foreign Exchange Loss', 'ar' => 'خسائر فروقات العملة'],

            ['code' => '5600', 'parent' => '5000', 'type' => AccountType::EXPENSE, 'postable' => false, 'en' => 'Other Expenses', 'ar' => 'مصروفات أخرى'],
            ['code' => '5610', 'parent' => '5600', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Miscellaneous Expense', 'ar' => 'مصروفات متنوعة'],
            ['code' => '5620', 'parent' => '5600', 'type' => AccountType::EXPENSE, 'postable' => true, 'en' => 'Loss on Disposal of Assets', 'ar' => 'خسائر بيع الأصول'],
        ];
    }

    private function normalBalance(AccountType $type): NormalBalance
    {
        return Account::normalBalanceForType($type);
    }
}
