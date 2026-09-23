-- Ejecutar UNA sola vez sobre la base de datos ESG ya instalada
ALTER TABLE tickets
    ADD COLUMN numero_identificacion_pc VARCHAR(100) NOT NULL DEFAULT '' AFTER pc_identificador;

ALTER TABLE tickets
    ADD INDEX idx_tickets_ni (numero_identificacion_pc);
