import fs from "node:fs/promises";
import path from "node:path";
import { Workbook } from "@oai/artifact-tool";

const sourcePath = "/tmp/branchos-balones.json";
const outputDir = "/Users/abel/Documents/tike/apps/voitity-api/outputs/branchos-balones";
const previewDir = "/Users/abel/Documents/tike/apps/voitity-api/.codex/tmp/branchos-balones/previews";
const products = JSON.parse(await fs.readFile(sourcePath, "utf8"));

const descriptions = new Map([
  [
    "61385",
    "Precio publicado: $68.000 COP. Balón Puma Orbita 6 MS unisex blanco, número 5, exterior TPU, ensamble cosido y peso aproximado de 440 g. Referencia 61385.",
  ],
  [
    "63012",
    "Precio publicado: $71.200 COP. Balón Adidas Tiro Club unisex blanco, número 5, exterior TPU, ensamble cosido y peso aproximado publicado de 945 g. Referencia 63012.",
  ],
  [
    "61388",
    "Precio publicado: $67.150 COP. Balón Puma Cumbre Conmebol Libertadores unisex blanco, número 5, exterior sintético, ensamble cosido y peso aproximado de 430 g. Referencia 61388.",
  ],
  [
    "60891",
    "Precio publicado: $48.300 COP. Balón Fila Coli unisex blanco para fútbol, con exterior TPU y peso aproximado de 310 g. Referencia 60891.",
  ],
  [
    "65164",
    "Precio publicado: $83.300 COP. Balón Puma Orbita Play PL Lights unisex amarillo, número 5, exterior sintético, ensamble cosido y peso aproximado de 415 g. Referencia 65164.",
  ],
  [
    "62781",
    "Precio publicado: $89.600 COP. Balón Nike Inter Milan Academy unisex azul, número 5, exterior de caucho, poliuretano y poliéster, ensamble vulcanizado y peso aproximado de 422 g. Referencia 62781.",
  ],
  [
    "62547",
    "Precio publicado: $54.400 COP. Balón Nike Phantom unisex blanco, número 5, exterior de caucho, poliuretano y poliéster, ensamble cosido y peso aproximado de 430 g. Referencia 62547.",
  ],
  [
    "62542",
    "Precio publicado: $63.200 COP. Balón Nike Pitch unisex blanco, número 5, exterior de caucho, poliuretano y poliéster, ensamble cosido y peso aproximado de 420 g. Referencia 62542.",
  ],
  [
    "62358",
    "Precio publicado: $58.400 COP. Balón Adidas Universadi unisex negro, número 5, exterior de poliéster, ensamble cosido y peso aproximado publicado de 935 g. Referencia 62358.",
  ],
  [
    "60842",
    "Precio publicado: $65.600 COP. Balón Puma Orbita 6 MS unisex blanco, número 5, exterior de caucho, EVA y TPU, ensamble cosido y peso aproximado de 419 g. Referencia 60842.",
  ],
  [
    "66870",
    "Precio publicado: $79.000 COP. Minibalón Adidas WC Final League unisex blanco, exterior TPU, ensamble cosido y peso aproximado publicado de 450 g. Referencia 66870.",
  ],
  [
    "66869",
    "Precio publicado: $199.000 COP. Balón Adidas WC Final League unisex blanco inspirado en la Copa Mundial FIFA 26, con exterior TPU, ensamble cosido y peso aproximado de 440 g. Referencia 66869.",
  ],
]);

const byReference = new Map(products.map((product) => [String(product.productReference), product]));

function row(reference) {
  const product = byReference.get(reference);

  if (!product) {
    throw new Error(`Missing Branchos product reference ${reference}`);
  }

  const image = product.items?.[0]?.images?.[0]?.imageUrl;
  const description = descriptions.get(reference);

  if (!description || !image || !product.link) {
    throw new Error(`Incomplete Branchos product reference ${reference}`);
  }

  return {
    name: product.productName,
    description,
    image,
    link: product.link,
  };
}

const firstRows = ["61385", "63012", "61388", "60891", "65164", "62781", "62547"].map(row);
const secondRows = ["61385", "62781", "62542", "62358", "60842", "66870", "66869"].map(row);

function csvCell(value) {
  const text = String(value).replaceAll('"', '""');
  return `"${text}"`;
}

function createCsv(rows) {
  const header = "name,description,image,link";
  const body = rows.map((item) =>
    [item.name, item.description, item.image, item.link].map(csvCell).join(",")
  );

  return `${[header, ...body].join("\r\n")}\r\n`;
}

async function verifyAndRender(csvText, previewName) {
  const workbook = await Workbook.fromCSV(csvText, { sheetName: "Products" });
  const sheet = workbook.worksheets.getItem("Products");
  const usedRange = sheet.getUsedRange();

  sheet.showGridLines = false;
  sheet.freezePanes.freezeRows(1);
  sheet.getRange("A1:D1").format = {
    fill: "#1769AA",
    font: { bold: true, color: "#FFFFFF" },
    rowHeight: 26,
  };
  usedRange.format.wrapText = true;
  sheet.getRange("A:A").format.columnWidth = 34;
  sheet.getRange("B:B").format.columnWidth = 72;
  sheet.getRange("C:D").format.columnWidth = 58;
  sheet.getRange(`A2:D${usedRange.rowCount}`).format.rowHeight = 52;

  const inspection = await workbook.inspect({
    kind: "table",
    range: `Products!A1:D${usedRange.rowCount}`,
    include: "values",
    tableMaxRows: 12,
    tableMaxCols: 4,
    tableMaxCellChars: 120,
  });
  const preview = await workbook.render({
    sheetName: "Products",
    range: `A1:D${usedRange.rowCount + 1}`,
    scale: 1,
    format: "png",
  });

  await fs.writeFile(
    path.join(previewDir, `${previewName}.png`),
    new Uint8Array(await preview.arrayBuffer())
  );

  return inspection.ndjson;
}

await fs.mkdir(outputDir, { recursive: true });
await fs.mkdir(previewDir, { recursive: true });

const firstCsv = createCsv(firstRows);
const secondCsv = createCsv(secondRows);
await fs.writeFile(path.join(outputDir, "balones-branchos-01.csv"), firstCsv, "utf8");
await fs.writeFile(path.join(outputDir, "balones-branchos-02-con-duplicados.csv"), secondCsv, "utf8");

const firstInspection = await verifyAndRender(firstCsv, "balones-branchos-01");
const secondInspection = await verifyAndRender(secondCsv, "balones-branchos-02-con-duplicados");

console.log(JSON.stringify({
  files: [
    {
      name: "balones-branchos-01.csv",
      rows: firstRows.length,
      references: firstRows.map((item) => item.description.match(/Referencia (\d+)/)?.[1]),
    },
    {
      name: "balones-branchos-02-con-duplicados.csv",
      rows: secondRows.length,
      references: secondRows.map((item) => item.description.match(/Referencia (\d+)/)?.[1]),
    },
  ],
  duplicates: ["61385", "62781"],
  inspections: [firstInspection, secondInspection],
}, null, 2));
