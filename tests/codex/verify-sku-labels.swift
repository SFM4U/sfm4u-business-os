import Foundation
import PDFKit
import Vision

let args = CommandLine.arguments
guard args.count >= 5, let pdf = PDFDocument(url: URL(fileURLWithPath: args[2])) else {
    fatalError("Usage: swift tests/verify-sku-labels.swift a4|thermal print.pdf page-*.png")
}
let thermal = args[1] == "thermal"
let expectedLabels = thermal ? 2 : 18
let expectedPages = 2
let widthMm = thermal ? 58.0 : 210.0
let heightMm = thermal ? 36.0 : 297.0
guard pdf.pageCount == expectedPages, args.count == expectedPages + 3 else {
    fatalError("Unexpected PDF page count: \(pdf.pageCount)")
}
let firstSku = "SFM-test-0123"
let secondSku = "0012345678901234567890123456"
var payloads: [String: Int] = [:]
for index in 0..<expectedPages {
    let page = pdf.page(at: index)!
    let size = page.bounds(for: .mediaBox).size
    let text = page.string ?? ""
    guard abs(size.width * 25.4 / 72 - widthMm) < 0.6,
          abs(size.height * 25.4 / 72 - heightMm) < 0.6,
          !text.contains("Admin controls") else { fatalError("Unexpected printable area for page \(index + 1)") }
    let scan = VNDetectBarcodesRequest()
    scan.symbologies = [.code128]
    try VNImageRequestHandler(url: URL(fileURLWithPath: args[index + 3])).perform([scan])
    let symbols = (scan.results ?? []).compactMap(\.payloadStringValue)
    guard !symbols.isEmpty, !thermal || symbols.count == 1 else {
        fatalError("Unexpected labels on page \(index + 1): \(symbols)")
    }
    for sku in symbols { payloads[sku, default: 0] += 1 }
}
guard payloads == [firstSku: expectedLabels / 2, secondSku: expectedLabels / 2] else {
    fatalError("Unexpected printed barcodes: \(payloads)")
}
print("PASS \(args[1]) dimensions, pagination and all \(expectedLabels) Code 128 labels decoded at 203 dpi")
