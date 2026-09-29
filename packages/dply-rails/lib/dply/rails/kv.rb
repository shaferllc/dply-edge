# frozen_string_literal: true

require "erb"
require "json"
require "net/http"
require "uri"

module Dply
  module Rails
    # Short values on the attached key-value store. Required from lib/dply/rails.rb.
    # Rails.cache uses DplyStore when DPLY_KV_HOST is set and REDIS_URL is not.
    # User: "add support for key value store to dply/laravel ad dply/rails".
    module Kv
      NO_COUNTERS = "Key-value stores can't count atomically. Attach Valkey (Redis) or use State for counters, locks and rate limiting."

      module_function

      def host
        ENV.fetch("DPLY_KV_HOST", "")
      end

      def write(key, value, expires_in: nil)
        request(Net::HTTP::Put, key, value.to_s, expires_in)
        nil
      end

      def read(key)
        response = request(Net::HTTP::Get, key)
        response.body if response.is_a?(Net::HTTPSuccess)
      end

      # Up to 100 keys in one request: { key => body or nil }.
      def read_many(keys)
        response = request(Net::HTTP::Post, "", JSON.generate(keys: keys))
        return {} unless response.is_a?(Net::HTTPSuccess)

        JSON.parse(response.body).fetch("values", {})
      end

      def delete(key)
        request(Net::HTTP::Delete, key)
        nil
      end

      # Every key, one listing page at a time.
      def clear
        cursor = nil
        loop do
          response = request(Net::HTTP::Get, "", query: cursor && { cursor: cursor })
          return false unless response.is_a?(Net::HTTPSuccess)

          page = JSON.parse(response.body)
          Array(page["keys"]).each { |name| delete(name) if name.is_a?(String) }
          cursor = page["cursor"]
          return true unless cursor.is_a?(String)
        end
      end

      def request(klass, key, body = nil, expires_in = nil, query: nil)
        raise "dply: DPLY_KV_HOST must be set" if host.empty?

        uri = URI("http://#{host}/#{ERB::Util.url_encode(key.to_s.sub(%r{\A/}, ""))}")
        uri.query = URI.encode_www_form(query) if query
        http = Net::HTTP.new(uri.host, uri.port)
        message = klass.new(uri)
        message["x-dply-ttl"] = expires_in.to_i.to_s if expires_in.to_i >= 60
        message["content-type"] = "application/json" if klass == Net::HTTP::Post
        message.body = body unless body.nil?
        http.request(message)
      end
    end
  end
end

module ActiveSupport
  module Cache
    class DplyStore < Store
      def initialize(options = nil)
        super(options)
        @host = options.is_a?(Hash) ? options[:host] : nil
      end

      def clear(**_options)
        on_host { Dply::Rails::Kv.clear }
      end

      def increment(_name, _amount = 1, **_options)
        raise NotImplementedError, Dply::Rails::Kv::NO_COUNTERS
      end

      def decrement(_name, _amount = 1, **_options)
        raise NotImplementedError, Dply::Rails::Kv::NO_COUNTERS
      end

      private

      def read_entry(key, **_options)
        body = on_host { Dply::Rails::Kv.read(key) }
        body.nil? ? nil : Entry.new(body)
      end

      def read_multi_entries(names, **options)
        keys = names.to_h { |name| [normalize_key(name, options), name] }
        on_host do
          keys.keys.each_slice(100).each_with_object({}) do |slice, found|
            Dply::Rails::Kv.read_many(slice).each { |key, body| found[keys[key]] = body unless body.nil? || !keys.key?(key) }
          end
        end
      end

      def write_entry(key, entry, **options)
        on_host { Dply::Rails::Kv.write(key, entry.value, expires_in: options[:expires_in]) }
        true
      end

      def delete_entry(key, **_options)
        on_host { Dply::Rails::Kv.delete(key) }
        true
      end

      def on_host
        previous = ENV.fetch("DPLY_KV_HOST", nil)
        ENV["DPLY_KV_HOST"] = @host if @host
        yield
      ensure
        ENV["DPLY_KV_HOST"] = previous unless previous.nil?
      end
    end
  end
end
