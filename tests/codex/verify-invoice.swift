import Foundation
import PDFKit
for file in CommandLine.arguments.dropFirst() {
    guard let pdf=PDFDocument(url:URL(fileURLWithPath:file)),pdf.pageCount==1,let page=pdf.page(at:0),let text=page.string else { fatalError("Expected one readable invoice page") }
    let box=page.bounds(for:.mediaBox)
    guard abs(box.width-210*72/25.4)<2,abs(box.height-297*72/25.4)<2,
          text.contains("SYNTHETIC-NOT-A-BIN"),text.contains("13.00"),
          text.range(of:#"R2-[0-9]{6}"#,options:.regularExpression) != nil,
          text.unicodeScalars.contains(where:{(0x0980...0x09ff).contains(Int($0.value))}) else { fatalError("Invoice dimensions, immutable BIN/serial/amount or Bangla text missing") }
    print("PASS runtime invoice PDF: A4, one page, recorded BIN/serial/BDT total and Bangla text")
}
