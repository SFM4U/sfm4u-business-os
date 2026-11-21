import Foundation
import Vision
var payloads = Set<String>()
for file in CommandLine.arguments.dropFirst() {
    let scan = VNDetectBarcodesRequest(); scan.symbologies = [.ean13]
    try VNImageRequestHandler(url: URL(fileURLWithPath:file)).perform([scan])
    for result in scan.results ?? [] { if let text=result.payloadStringValue { payloads.insert(text) } }
}
let expected: Set<String> = ["2000000000015", "2000000000022"]
guard payloads == expected else { fatalError("Unexpected EAN-13 PDF raster decode: \(payloads)") }
print("PASS both distinct synthetic piece/carton EAN-13 symbols decoded from PDF raster")
