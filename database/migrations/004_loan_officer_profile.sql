INSERT INTO role_permissions(role, permission)
SELECT 'Loan Officer', 'portfolio.all' WHERE EXISTS (SELECT 1 FROM roles WHERE role = 'Loan Officer')
  AND NOT EXISTS (SELECT 1 FROM role_permissions WHERE role = 'Loan Officer' AND permission = 'portfolio.all');
DELETE FROM role_permissions WHERE role = 'Loan Officer' AND permission IN ('payment.view', 'audit.own');
