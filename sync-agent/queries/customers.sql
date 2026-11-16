-- POC PLACEHOLDER ONLY: Confirm Bplus ERP schema and read-only VIEW before running.
-- Required column aliases exactly as below.
SELECT TOP (250)
  CAST(customer_code AS varchar(64)) AS erp_customer_code,
  CAST(customer_name AS nvarchar(255)) AS customer_name,
  CAST(phone AS varchar(40)) AS phone,
  CAST(salesperson_code AS varchar(64)) AS salesperson_code
FROM dbo.vw_crm_customers
ORDER BY customer_code;
