-- Read-only SELECT query mapped from Bplus ERP MEMBER + MBTYPE + MBPOINT (SQL05090002, MP0010011)
SELECT TOP (?)
  CAST(MEMBER.MB_KEY AS bigint) AS erp_row_key,
  CAST(MEMBER.MB_CODE AS varchar(64)) AS member_code,
  CAST(MEMBER.MB_INTL AS nvarchar(40)) AS title,
  CAST(NULLIF(LTRIM(RTRIM(
    COALESCE(MEMBER.MB_NAME, N'') + N' ' + COALESCE(MEMBER.MB_SURNME, N'')
  )), N'') AS nvarchar(255)) AS full_name,
  CAST(MEMBER.MB_PHONE AS varchar(64)) AS phone,
  CAST(MEMBER.MB_PHONE_HND AS varchar(64)) AS mobile_phone,
  CAST(MEMBER.MB_EMAIL AS varchar(180)) AS email,
  CAST(NULLIF(LTRIM(RTRIM(
    COALESCE(MEMBER.MB_ADDR_1, N'') + N' ' +
    COALESCE(MEMBER.MB_ADDR_2, N'') + N' ' +
    COALESCE(MEMBER.MB_ADDR_3, N'') + N' ' +
    COALESCE(MEMBER.MB_POST, N'')
  )), N'') AS nvarchar(500)) AS address,
  CAST(MBTYPE.MT_CODE AS varchar(32)) AS member_type_code,
  CAST(MBTYPE.MT_NAME AS nvarchar(120)) AS member_type_name,
  MEMBER.MB_SINCE AS member_since,
  MEMBER.MB_EXPIRE AS member_expire,
  CAST(COALESCE(PTS.erp_point_balance, 0) AS int) AS erp_point_balance,
  CAST(COALESCE(PTS.erp_redeemed_total, 0) AS int) AS erp_redeemed_total,
  CAST(COALESCE(PTS.erp_spent_total, 0) AS decimal(16, 2)) AS erp_spent_total,
  PTS.last_point_at AS last_point_at
FROM MEMBER
LEFT JOIN MBTYPE
  ON MBTYPE.MT_KEY = MEMBER.MB_MT
LEFT JOIN (
  SELECT
    MBP_MB,
    SUM(COALESCE(MBP_POINT, 0)) AS erp_point_balance,
    SUM(COALESCE(MBP_RDM, 0)) AS erp_redeemed_total,
    SUM(COALESCE(MBP_AMT, 0)) AS erp_spent_total,
    MAX(MBP_DATE) AS last_point_at
  FROM MBPOINT
  GROUP BY MBP_MB
) AS PTS
  ON PTS.MBP_MB = MEMBER.MB_KEY
WHERE MEMBER.MB_KEY > ?
  AND MEMBER.MB_CODE IS NOT NULL
ORDER BY MEMBER.MB_KEY ASC;
