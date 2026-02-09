<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // Tabel: supplier_invoices
       


      

        // Trigger: Auto-update payment status setelah payment
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_supplier_payment_status;
            
            CREATE TRIGGER trg_supplier_payment_status
            AFTER INSERT ON supplierpayments
            FOR EACH ROW
            BEGIN
                DECLARE total_paid DECIMAL(15,2);
                DECLARE invoice_total DECIMAL(15,2);
                
                -- Calculate total paid
                SELECT SUM(amount) INTO total_paid 
                FROM supplierpayments 
                WHERE supplierinvoiceid = NEW.supplierinvoiceid 
                AND status = 'success';
                
                -- Get invoice total
                SELECT totalamount INTO invoice_total 
                FROM supplierinvoices 
                WHERE supplierinvoiceid = NEW.supplierinvoiceid;
                
                -- Update invoice payment status
                UPDATE supplierinvoices 
                SET paidamount = COALESCE(total_paid, 0),
                    paymentstatus = CASE 
                        WHEN COALESCE(total_paid, 0) >= invoice_total THEN 'paid'
                        WHEN COALESCE(total_paid, 0) > 0 THEN 'partial'
                        ELSE 'unpaid'
                    END
                WHERE supplierinvoiceid = NEW.supplierinvoiceid;
                
                -- Log activity
                INSERT INTO activitylogs (userid, action, module, recordid, description, createdat)
                VALUES (NEW.createdby, 'create', 'supplierpayments', NEW.supplierpaymentid, 
                        CONCAT('Supplier payment of ', NEW.amount, ' recorded'), NOW());
            END;
        ");

        // Trigger: Update invoice total saat item berubah
        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_supplier_invoice_items_insert;
            
            CREATE TRIGGER trg_supplier_invoice_items_insert
            AFTER INSERT ON supplierinvoiceitems
            FOR EACH ROW
            BEGIN
                UPDATE supplierinvoices 
                SET totalamount = (
                    SELECT COALESCE(SUM(subtotal), 0) 
                    FROM supplierinvoiceitems 
                    WHERE supplierinvoiceid = NEW.supplierinvoiceid
                )
                WHERE supplierinvoiceid = NEW.supplierinvoiceid;
            END;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_supplier_invoice_items_update;
            
            CREATE TRIGGER trg_supplier_invoice_items_update
            AFTER UPDATE ON supplierinvoiceitems
            FOR EACH ROW
            BEGIN
                UPDATE supplierinvoices 
                SET totalamount = (
                    SELECT COALESCE(SUM(subtotal), 0) 
                    FROM supplierinvoiceitems 
                    WHERE supplierinvoiceid = NEW.supplierinvoiceid
                )
                WHERE supplierinvoiceid = NEW.supplierinvoiceid;
            END;
        ");

        DB::unprepared("
            DROP TRIGGER IF EXISTS trg_supplier_invoice_items_delete;
            
            CREATE TRIGGER trg_supplier_invoice_items_delete
            AFTER DELETE ON supplierinvoiceitems
            FOR EACH ROW
            BEGIN
                UPDATE supplierinvoices 
                SET totalamount = (
                    SELECT COALESCE(SUM(subtotal), 0) 
                    FROM supplierinvoiceitems 
                    WHERE supplierinvoiceid = OLD.supplierinvoiceid
                )
                WHERE supplierinvoiceid = OLD.supplierinvoiceid;
            END;
        ");
    }

    public function down()
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_payment_status');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_invoice_items_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_invoice_items_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_invoice_items_delete');
        
        Schema::dropIfExists('supplierpayments');
        Schema::dropIfExists('supplierinvoiceitems');
        Schema::dropIfExists('supplierinvoices');
    }
};
