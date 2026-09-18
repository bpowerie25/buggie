import Foundation

public enum BuggieError: Error, Equatable {
    case missingKey
    case quotaReached(String)
    case rateLimited(retryAfter: Int?)
    case rejected(status: Int, message: String?)
    case transport(String)
}

public struct IngestResponse: Decodable, Sendable {
    public let id: Int
    public let reference: String
    public let uploadURL: String?

    enum CodingKeys: String, CodingKey {
        case id, reference
        case uploadURL = "upload_url"
    }
}

/// Posts a report, and the screenshot that may follow it.
///
/// The screenshot goes to its own short-lived signed URL rather than inside the JSON,
/// so a large image never passes through the ingest endpoint — the same arrangement
/// the web widget uses.
public struct Transport: Sendable {
    public let endpoint: URL
    public let key: String
    private let session: URLSession

    public init(endpoint: URL, key: String, session: URLSession = .shared) {
        self.endpoint = endpoint
        self.key = key
        self.session = session
    }

    public func send(_ report: Report) async throws -> IngestResponse {
        guard !key.isEmpty else { throw BuggieError.missingKey }

        var request = URLRequest(url: endpoint.appendingPathComponent("api/ingest/\(key)"))
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try JSONEncoder().encode(report)

        // Never carry the user's cookies to us.
        request.httpShouldHandleCookies = false

        let (data, response): (Data, URLResponse)

        do {
            (data, response) = try await session.data(for: request)
        } catch {
            throw BuggieError.transport(error.localizedDescription)
        }

        guard let http = response as? HTTPURLResponse else {
            throw BuggieError.transport("No HTTP response.")
        }

        guard (200 ..< 300).contains(http.statusCode) else {
            throw Self.failure(status: http.statusCode, data: data, headers: http)
        }

        return try JSONDecoder().decode(IngestResponse.self, from: data)
    }

    /// Upload the screenshot to the signed URL the ingest response handed back.
    public func upload(screenshot data: Data, to urlString: String) async throws {
        guard let url = URL(string: urlString) else {
            throw BuggieError.transport("Bad upload URL.")
        }

        let boundary = "buggie.\(UUID().uuidString)"
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.httpShouldHandleCookies = false
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        request.httpBody = Self.multipart(data: data, boundary: boundary)

        do {
            _ = try await session.data(for: request)
        } catch {
            // A missing screenshot is not worth failing an otherwise good report.
            throw BuggieError.transport(error.localizedDescription)
        }
    }

    static func multipart(data: Data, boundary: String, filename: String = "screenshot.jpg") -> Data {
        var body = Data()

        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"screenshot\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: image/jpeg\r\n\r\n".data(using: .utf8)!)
        body.append(data)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)

        return body
    }

    /// Turn a refusal into something the host app can act on, rather than a bare code.
    static func failure(status: Int, data: Data, headers: HTTPURLResponse) -> BuggieError {
        let message = (try? JSONSerialization.jsonObject(with: data) as? [String: Any])?["message"] as? String

        switch status {
        case 402:
            return .quotaReached(message ?? "This account has reached its monthly report limit.")
        case 429:
            let retry = (headers.value(forHTTPHeaderField: "Retry-After")).flatMap(Int.init)
            return .rateLimited(retryAfter: retry)
        default:
            return .rejected(status: status, message: message)
        }
    }
}
