import Foundation
import PDFKit

guard CommandLine.arguments.count == 3,
      let pdf = PDFDocument(url: URL(fileURLWithPath: CommandLine.arguments[1])),
      pdf.pageCount == 1, let page = pdf.page(at: 0), let content = page.string else {
    fatalError("Expected a single readable receipt PDF")
}
let media = page.bounds(for: .mediaBox)
let width = CommandLine.arguments[2] == "a4" ? 210.0 : 80.0
guard abs(media.width - width * 72 / 25.4) < 2,
      content.contains("Receipt"), content.contains("COPY"), content.contains("BDT 100.00"),
      content.contains("1 case"), content.unicodeScalars.contains(where: { (0x0980...0x09ff).contains(Int($0.value)) }) else {
    fatalError("Receipt PDF is missing the expected paper width or bilingual content")
}
print("PASS browser PDF receipt: \(Int(width)) mm, Bengali and English, one case, BDT 100.00, COPY")
