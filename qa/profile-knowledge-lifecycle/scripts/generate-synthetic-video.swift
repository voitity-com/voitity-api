import AVFoundation
import CoreGraphics
import Foundation

guard CommandLine.arguments.count == 2 else {
    fputs("Usage: swift generate-synthetic-video.swift <output.mp4>\n", stderr)
    exit(2)
}

let outputURL = URL(fileURLWithPath: CommandLine.arguments[1])
try? FileManager.default.removeItem(at: outputURL)

let width = 1280
let height = 720
let framesPerSecond: Int32 = 30
let durationSeconds = 4
let writer = try AVAssetWriter(outputURL: outputURL, fileType: .mp4)
let input = AVAssetWriterInput(
    mediaType: .video,
    outputSettings: [
        AVVideoCodecKey: AVVideoCodecType.h264,
        AVVideoWidthKey: width,
        AVVideoHeightKey: height,
        AVVideoCompressionPropertiesKey: [
            AVVideoAverageBitRateKey: 1_200_000,
            AVVideoProfileLevelKey: AVVideoProfileLevelH264HighAutoLevel,
        ],
    ]
)
input.expectsMediaDataInRealTime = false

let adaptor = AVAssetWriterInputPixelBufferAdaptor(
    assetWriterInput: input,
    sourcePixelBufferAttributes: [
        kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
        kCVPixelBufferWidthKey as String: width,
        kCVPixelBufferHeightKey as String: height,
    ]
)

guard writer.canAdd(input) else {
    fatalError("Unable to add video input")
}

writer.add(input)
writer.startWriting()
writer.startSession(atSourceTime: .zero)

let colors: [(CGFloat, CGFloat, CGFloat)] = [
    (0.06, 0.16, 0.16),
    (0.09, 0.42, 0.53),
    (0.39, 0.80, 0.77),
    (0.96, 0.70, 0.20),
]

for frame in 0..<(Int(framesPerSecond) * durationSeconds) {
    while !input.isReadyForMoreMediaData {
        usleep(1_000)
    }

    var pixelBuffer: CVPixelBuffer?
    guard let pool = adaptor.pixelBufferPool,
          CVPixelBufferPoolCreatePixelBuffer(nil, pool, &pixelBuffer) == kCVReturnSuccess,
          let buffer = pixelBuffer else {
        fatalError("Unable to allocate pixel buffer")
    }

    CVPixelBufferLockBaseAddress(buffer, [])
    defer { CVPixelBufferUnlockBaseAddress(buffer, []) }

    guard let baseAddress = CVPixelBufferGetBaseAddress(buffer),
          let context = CGContext(
            data: baseAddress,
            width: width,
            height: height,
            bitsPerComponent: 8,
            bytesPerRow: CVPixelBufferGetBytesPerRow(buffer),
            space: CGColorSpaceCreateDeviceRGB(),
            bitmapInfo: CGImageAlphaInfo.premultipliedFirst.rawValue | CGBitmapInfo.byteOrder32Little.rawValue
          ) else {
        fatalError("Unable to create drawing context")
    }

    let colorIndex = (frame / Int(framesPerSecond)) % colors.count
    let color = colors[colorIndex]
    context.setFillColor(red: color.0, green: color.1, blue: color.2, alpha: 1)
    context.fill(CGRect(x: 0, y: 0, width: width, height: height))

    let progress = CGFloat(frame % Int(framesPerSecond)) / CGFloat(framesPerSecond)
    context.setFillColor(red: 1, green: 1, blue: 1, alpha: 0.92)
    context.fillEllipse(in: CGRect(x: 110 + progress * 900, y: 250, width: 220, height: 220))
    context.setFillColor(red: 0.06, green: 0.16, blue: 0.16, alpha: 0.75)
    context.fill(CGRect(x: 120, y: 110, width: 1040 * progress, height: 38))

    let presentationTime = CMTime(value: CMTimeValue(frame), timescale: framesPerSecond)
    guard adaptor.append(buffer, withPresentationTime: presentationTime) else {
        fatalError(writer.error?.localizedDescription ?? "Unable to append frame")
    }
}

input.markAsFinished()
let semaphore = DispatchSemaphore(value: 0)
writer.finishWriting { semaphore.signal() }
semaphore.wait()

guard writer.status == .completed else {
    fatalError(writer.error?.localizedDescription ?? "Video writer failed")
}

print(outputURL.path)
