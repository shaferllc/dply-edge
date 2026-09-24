# frozen_string_literal: true

require "net/http"
require "uri"

module Dply
  module Rails
    # Files on the attached bucket. Same calls as an S3 client: put, get, delete.
    # The host is injected as DPLY_STORAGE_HOST. Required from lib/dply/rails.rb.
    module Storage
      module_function

      def host
        ENV.fetch("DPLY_STORAGE_HOST", "")
      end

      def put(key, body)
        request(Net::HTTP::Put, key, body)
      end

      def get(key)
        response = request(Net::HTTP::Get, key)
        response.body if response.is_a?(Net::HTTPSuccess)
      end

      def delete(key)
        request(Net::HTTP::Delete, key)
        nil
      end

      def request(klass, key, body = nil)
        raise "dply: DPLY_STORAGE_HOST must be set" if host.empty?

        uri = URI("http://#{host}/#{key.to_s.sub(%r{\A/}, "")}")
        http = Net::HTTP.new(uri.host, uri.port)
        message = klass.new(uri)
        message.body = body unless body.nil?
        http.request(message)
      end
    end
  end
end
