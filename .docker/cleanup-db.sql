-- ============================================================
-- SOLWED ERP — Database cleanup script
-- Tested in dev, ready for production
-- Run: ssh solwed.es "docker exec -i postgres psql -U fs_user -d facturascripts" < cleanup-db.sql
-- ============================================================

SET search_path TO facturascripts, public;
BEGIN;

-- ─── 1. DELETE ORPHAN CLIENTS (no contact, no documents) ─────
DELETE FROM clientes c
WHERE NOT EXISTS (SELECT 1 FROM contactos co WHERE co.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM facturascli f WHERE f.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM albaranescli a WHERE a.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM presupuestoscli p WHERE p.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM pedidoscli pe WHERE pe.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM recibospagoscli r WHERE r.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM proyectos pr WHERE pr.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM anticipos an WHERE an.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM cuentasbcocli cb WHERE cb.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM docrecurrentes_sale dr WHERE dr.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM serviciosat sa WHERE sa.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM serviciosat_maquinas sm WHERE sm.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM comisiones co2 WHERE co2.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM df_documentos df WHERE df.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM portal_notes pn WHERE pn.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM prepagos pp WHERE pp.codcliente = c.codcliente);
-- Expected: ~590 rows

-- ─── 2. LINK CONTACTS → CLIENTS by email ────────────────────
UPDATE contactos co
SET codcliente = c.codcliente
FROM clientes c
WHERE lower(co.email) = lower(c.email)
AND co.codcliente IS NULL
AND NOT EXISTS (SELECT 1 FROM contactos co2 WHERE co2.codcliente = c.codcliente)
AND c.email IS NOT NULL AND c.email != '';

-- ─── 3. LINK CONTACTS → CLIENTS by CIF ──────────────────────
UPDATE contactos co
SET codcliente = c.codcliente
FROM clientes c
WHERE upper(replace(replace(co.cifnif, '-', ''), ' ', '')) = upper(replace(replace(c.cifnif, '-', ''), ' ', ''))
AND co.codcliente IS NULL
AND NOT EXISTS (SELECT 1 FROM contactos co2 WHERE co2.codcliente = c.codcliente)
AND c.cifnif IS NOT NULL AND c.cifnif != ''
AND co.cifnif IS NOT NULL AND co.cifnif != '';

-- ─── 4. DELETE CONTACTS without email, phone, or client ──────
DELETE FROM contactos co
WHERE (email IS NULL OR email = '')
  AND (telefono1 IS NULL OR telefono1 = '')
  AND (telefono2 IS NULL OR telefono2 = '')
  AND codcliente IS NULL
  AND NOT EXISTS (SELECT 1 FROM solwedes_suscripciones s WHERE s.idcontacto = co.idcontacto)
  AND NOT EXISTS (SELECT 1 FROM solwedes_dominios d WHERE d.idcontacto = co.idcontacto)
  AND NOT EXISTS (SELECT 1 FROM solwedes_pagos p WHERE p.idcontacto = co.idcontacto)
  AND NOT EXISTS (SELECT 1 FROM solwedes_accesos_servicios a WHERE a.idcontacto = co.idcontacto);
-- Expected: ~70 rows

-- ─── 5. MERGE DUPLICATE CONTACTS (same email) ───────────────
CREATE TEMP TABLE merge_winners AS
SELECT DISTINCT ON (lower(email))
  idcontacto as winner_id, lower(email) as em
FROM contactos
WHERE email IS NOT NULL AND email != ''
  AND lower(email) IN (SELECT lower(email) FROM contactos WHERE email IS NOT NULL AND email != '' GROUP BY lower(email) HAVING count(*) > 1)
ORDER BY lower(email),
  CASE WHEN codcliente IS NOT NULL THEN 0 ELSE 1 END,
  CASE WHEN cifnif IS NOT NULL AND cifnif != '' AND cifnif != 'PENDIENTE' AND cifnif != '00000000T' THEN 0 ELSE 1 END,
  CASE WHEN telefono1 IS NOT NULL AND telefono1 != '' THEN 0 ELSE 1 END,
  idcontacto ASC;

CREATE TEMP TABLE merge_losers AS
SELECT co.idcontacto as loser_id, w.winner_id
FROM contactos co
JOIN merge_winners w ON lower(co.email) = w.em
WHERE co.idcontacto != w.winner_id;

-- Reassign FK references from losers to winners
UPDATE solwedes_suscripciones SET idcontacto = ml.winner_id FROM merge_losers ml WHERE idcontacto = ml.loser_id;
UPDATE solwedes_pagos SET idcontacto = ml.winner_id FROM merge_losers ml WHERE idcontacto = ml.loser_id;
UPDATE solwedes_dominios SET idcontacto = ml.winner_id FROM merge_losers ml WHERE idcontacto = ml.loser_id;
UPDATE solwedes_accesos_servicios SET idcontacto = ml.winner_id FROM merge_losers ml WHERE idcontacto = ml.loser_id;

-- Copy codcliente from loser to winner if winner doesn't have one
UPDATE contactos co
SET codcliente = sub.codcliente
FROM (
  SELECT DISTINCT ON (ml.winner_id) ml.winner_id, loser.codcliente
  FROM merge_losers ml
  JOIN contactos loser ON loser.idcontacto = ml.loser_id
  WHERE loser.codcliente IS NOT NULL
  ORDER BY ml.winner_id, ml.loser_id
) sub
WHERE co.idcontacto = sub.winner_id AND co.codcliente IS NULL;

-- Unlink and delete losers
UPDATE contactos SET codcliente = NULL WHERE idcontacto IN (SELECT loser_id FROM merge_losers) AND codcliente IS NOT NULL;
DELETE FROM contactos WHERE idcontacto IN (SELECT loser_id FROM merge_losers);
-- Expected: ~379 rows

DROP TABLE merge_winners;
DROP TABLE merge_losers;

-- ─── 6. DELETE orphan clients created by merge ───────────────
DELETE FROM clientes c
WHERE NOT EXISTS (SELECT 1 FROM contactos co WHERE co.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM facturascli f WHERE f.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM albaranescli a WHERE a.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM presupuestoscli p WHERE p.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM pedidoscli pe WHERE pe.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM recibospagoscli r WHERE r.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM proyectos pr WHERE pr.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM anticipos an WHERE an.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM cuentasbcocli cb WHERE cb.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM docrecurrentes_sale dr WHERE dr.codcliente = c.codcliente)
AND NOT EXISTS (SELECT 1 FROM serviciosat sa WHERE sa.codcliente = c.codcliente);

-- ─── 7. DELETE TEST COMPANIES (keep 1=Iván, 2=SOLWED, 4=JoseFerran, 16=RSPubli) ──

-- Delete sale document lines for companies to remove
DELETE FROM lineasfacturascli WHERE idfactura IN (SELECT idfactura FROM facturascli WHERE idempresa NOT IN (1,2,4,16));
DELETE FROM lineasalbaranescli WHERE idalbaran IN (SELECT idalbaran FROM albaranescli WHERE idempresa NOT IN (1,2,4,16));
DELETE FROM lineaspedidoscli WHERE idpedido IN (SELECT idpedido FROM pedidoscli WHERE idempresa NOT IN (1,2,4,16));
DELETE FROM lineaspresupuestoscli WHERE idpresupuesto IN (SELECT idpresupuesto FROM presupuestoscli WHERE idempresa NOT IN (1,2,4,16));

-- Delete receipts/payments linked to invoices
DELETE FROM pagoscli WHERE idrecibo IN (SELECT idrecibo FROM recibospagoscli WHERE idfactura IN (SELECT idfactura FROM facturascli WHERE idempresa NOT IN (1,2,4,16)));
DELETE FROM recibospagoscli WHERE idfactura IN (SELECT idfactura FROM facturascli WHERE idempresa NOT IN (1,2,4,16));

-- Delete transformations
DELETE FROM doctransformations WHERE model1 = 'FacturaCliente' AND iddoc1 IN (SELECT idfactura FROM facturascli WHERE idempresa NOT IN (1,2,4,16));
DELETE FROM doctransformations WHERE model2 = 'FacturaCliente' AND iddoc2 IN (SELECT idfactura FROM facturascli WHERE idempresa NOT IN (1,2,4,16));

-- Delete sale documents
DELETE FROM facturascli WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM albaranescli WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM pedidoscli WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM presupuestoscli WHERE idempresa NOT IN (1,2,4,16);

-- Delete accounting
DELETE FROM partidas WHERE idasiento IN (SELECT idasiento FROM asientos WHERE idempresa NOT IN (1,2,4,16));
DELETE FROM asientos WHERE idempresa NOT IN (1,2,4,16);

-- Reassign shared resources to SOLWED (empresa 2)
UPDATE ejercicios SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);
UPDATE almacenes SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);
UPDATE formaspago SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);
UPDATE secuencias_documentos SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);
UPDATE formatos_documentos SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);
UPDATE users SET idempresa = 2 WHERE idempresa NOT IN (1,2,4,16);

-- Delete non-shared resources
DELETE FROM cuentasbanco WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM emails_empresas WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM docrecurrentes_sale WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM docrecurrentes_purchase WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM comisiones WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM comisionespenalizaciones WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM liquidacionescomisiones WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM proyectos WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM serviciosat WHERE idempresa NOT IN (1,2,4,16);
DELETE FROM regularizacionimpuestos WHERE idempresa NOT IN (1,2,4,16);

-- Delete companies
DELETE FROM empresas WHERE idempresa NOT IN (1,2,4,16);
-- Expected: ~12 companies

-- ─── 8. CLEAN EMPTY ALMACENES + EJERCICIOS ──────────────────
DELETE FROM almacenes WHERE codalmacen NOT IN ('1', 'ALG', '3', '15');
DELETE FROM ejercicios WHERE codejercicio IN ('0001','0002','0003','0004','0005','0007');

-- ─── 9. CLEAN DUPLICATE PAYMENT METHODS ──────────────────────
DELETE FROM formaspago WHERE codpago IN ('15','16','17','18','19','20','21','22','23','24','25','28') AND descripcion = 'Por defecto';
DELETE FROM formaspago WHERE codpago = '11' AND descripcion LIKE 'Paypal%';

-- ─── 10. SET SEPA AS DEFAULT PAYMENT FOR ALL SOLWED DOCS ─────
UPDATE facturascli SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE albaranescli SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE pedidoscli SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE presupuestoscli SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE facturasprov SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE albaranesprov SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE pedidosprov SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE presupuestosprov SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';
UPDATE clientes SET codpago = '27' WHERE codpago != '27';
UPDATE docrecurrentes_sale SET codpago = '27' WHERE idempresa = 2 AND codpago != '27';

COMMIT;

-- ─── VERIFY ──────────────────────────────────────────────────
SELECT
  (SELECT count(*) FROM contactos) as contactos,
  (SELECT count(*) FROM clientes) as clientes,
  (SELECT count(*) FROM empresas) as empresas,
  (SELECT count(*) FROM almacenes) as almacenes,
  (SELECT count(*) FROM ejercicios) as ejercicios,
  (SELECT count(*) FROM formaspago) as formas_pago;
