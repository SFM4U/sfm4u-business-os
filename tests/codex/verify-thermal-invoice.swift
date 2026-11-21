import Foundation
import PDFKit
let file=CommandLine.arguments[1]
guard let pdf=PDFDocument(url:URL(fileURLWithPath:file)),pdf.pageCount==1,let page=pdf.page(at:0),let text=page.string else {fatalError("Expected single readable thermal receipt")}
let box=page.bounds(for:.mediaBox)
guard abs(box.width-80*72/25.4)<2,text.contains("COPY"),text.contains("13.00"),text.range(of:#"R2-[0-9]{6}"#,options:.regularExpression) != nil,text.unicodeScalars.contains(where:{(0x0980...0x09ff).contains(Int($0.value))}) else {fatalError("Thermal dimensions, original number, amount or Bengali missing")}
print("PASS runtime thermal invoice: 80mm, one page, COPY, immutable serial/amount and Bengali")
